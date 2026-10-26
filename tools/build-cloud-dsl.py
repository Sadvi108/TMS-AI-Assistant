"""
Builds the Dify Cloud version of the TMS Assistant Chatflow from the local export.

Changes to the exported graph:
  - Knowledge Retrieval points at the cloud knowledge base and its embedding model.
  - New "Exact FAQ match" code node + "Exact FAQ?" branch: when the visitor asked
    (almost) exactly an FAQ question, the "Verified FAQ answer" node (id faq_answer)
    returns that FAQ's answer word for word; everything else goes to the LLM.
  - The Ollama plugin dependency is dropped (Dify Cloud uses a cloud embedding model).

Needs PyYAML. Inputs come from environment variables:
  DSL_B64, CODE_B64        base64 of the exported DSL and of tools/dify-faq-match.py
  DATASET_ID               cloud knowledge base id (plain UUID)
  EMB_PROVIDER, EMB_MODEL  e.g. langgenius/gemini/google, gemini-embedding-001
Writes the new DSL to stdout.
"""

import base64
import os
import sys

import yaml

dsl = yaml.safe_load(base64.b64decode(os.environ['DSL_B64']))
code = base64.b64decode(os.environ['CODE_B64']).decode()

dsl['dependencies'] = [
    d for d in dsl.get('dependencies', [])
    if 'ollama' not in d.get('value', {}).get('marketplace_plugin_unique_identifier', '')
]

graph = dsl['workflow']['graph']
nodes = {n['id']: n for n in graph['nodes']}
START, RETRIEVAL = '1792564793090', '1792564903991'

retrieval = nodes[RETRIEVAL]['data']
retrieval['dataset_ids'] = [os.environ['DATASET_ID']]
config = retrieval['multiple_retrieval_config']
config['top_k'] = 5
config['weights']['vector_setting']['embedding_provider_name'] = os.environ['EMB_PROVIDER']
config['weights']['vector_setting']['embedding_model_name'] = os.environ['EMB_MODEL']


def node(node_id, x, y, data, height=54):
    return {
        'data': {'selected': False, **data},
        'height': height,
        'id': node_id,
        'position': {'x': x, 'y': y},
        'positionAbsolute': {'x': x, 'y': y},
        'sourcePosition': 'right',
        'targetPosition': 'left',
        'type': 'custom',
        'width': 242,
        'zIndex': 0,
    }


def edge(source, target, source_type, target_type, handle='source'):
    return {
        'data': {
            'isInIteration': False,
            'isInLoop': False,
            'iteration_id': None,
            'loop_id': None,
            'sourceType': source_type,
            'targetType': target_type,
        },
        'id': f'{source}-{handle}-{target}-target',
        'source': source,
        'sourceHandle': handle,
        'target': target,
        'targetHandle': 'target',
        'type': 'custom',
        'zIndex': 0,
    }


faq_match = node('faq_match', 680, 282, {
    'title': 'Exact FAQ match',
    'type': 'code',
    'code_language': 'python3',
    'code': code,
    'variables': [
        {'variable': 'result', 'value_selector': [RETRIEVAL, 'result'], 'value_type': 'array[object]'},
        {'variable': 'query', 'value_selector': ['sys', 'query'], 'value_type': 'string'},
    ],
    'outputs': {
        'answer': {'type': 'string', 'children': None},
        'similarity': {'type': 'number', 'children': None},
    },
    # Dify Cloud's code sandbox occasionally times out. Retry, and if it still fails,
    # report "no match" so the question goes to the LLM instead of failing the answer.
    'retry_config': {'retry_enabled': True, 'max_retries': 2, 'retry_interval': 1000},
    'error_strategy': 'default-value',
    'default_value': [
        {'key': 'answer', 'type': 'string', 'value': ''},
        {'key': 'similarity', 'type': 'number', 'value': 0},
    ],
})
is_faq = node('is_faq', 980, 282, {
    'title': 'Exact FAQ?',
    'type': 'if-else',
    'cases': [{
        'case_id': 'true',
        'id': 'true',
        'logical_operator': 'and',
        'conditions': [{
            'id': 'faq-answer-not-empty',
            'varType': 'string',
            'variable_selector': ['faq_match', 'answer'],
            'comparison_operator': 'not empty',
            'value': '',
        }],
    }],
}, height=126)
faq_answer = node('faq_answer', 1280, 160, {
    'title': 'Verified FAQ answer',
    'type': 'answer',
    'answer': '{{#faq_match.answer#}}',
    'variables': [],
}, height=103)

for node_id, x, y in (('llm', 1280, 400), ('answer', 1580, 400)):
    nodes[node_id]['position'] = nodes[node_id]['positionAbsolute'] = {'x': x, 'y': y}
nodes['llm']['data']['selected'] = False
nodes['llm']['selected'] = False
# Groq's free tier can rate-limit. Retry, then fall back to the prompt's own "can't answer"
# sentence, which the website shows as the contact-TMS card rather than an error.
nodes['llm']['data']['retry_config'] = {'retry_enabled': True, 'max_retries': 2, 'retry_interval': 2000}
nodes['llm']['data']['error_strategy'] = 'default-value'
nodes['llm']['data']['default_value'] = [{
    'key': 'text',
    'type': 'string',
    'value': "I'm sorry, I can't answer that from TMS information right now. Please contact TMS support.",
}]

graph['nodes'] = [nodes[START], nodes[RETRIEVAL], faq_match, is_faq, faq_answer, nodes['llm'], nodes['answer']]
graph['edges'] = [
    edge(START, RETRIEVAL, 'start', 'knowledge-retrieval'),
    edge(RETRIEVAL, 'faq_match', 'knowledge-retrieval', 'code'),
    edge('faq_match', 'is_faq', 'code', 'if-else'),
    edge('is_faq', 'faq_answer', 'if-else', 'answer', handle='true'),
    edge('is_faq', 'llm', 'if-else', 'llm', handle='false'),
    edge('llm', 'answer', 'llm', 'answer'),
]

yaml.safe_dump(dsl, sys.stdout, allow_unicode=True, sort_keys=False, width=1000)
