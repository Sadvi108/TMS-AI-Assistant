# Code for the "Exact FAQ match" node in the Dify Cloud Chatflow (see tools/build-cloud-dsl.py).
# Dify passes the Knowledge Retrieval results and the visitor's question; when the question is
# (almost) exactly an FAQ question, the node returns that FAQ's answer word for word.

import re
from difflib import SequenceMatcher


def _norm(text):
    return ' '.join(re.findall(r'[a-z0-9]+', (text or '').lower()))


def main(result: list, query: str) -> dict:
    """Return an FAQ answer word for word when the visitor asked (almost) exactly that FAQ question."""
    q = _norm(query)
    best_answer, best_score = '', 0.0
    for item in result or []:
        content = item.get('content', '') if isinstance(item, dict) else str(item)
        for block in re.split(r'\n?#{3,}\n?', content):
            # Dify may fold the chunk onto one line ("Topic: ... Q: ... A: ..."), so don't rely on newlines.
            m = re.search(r'Q:\s*(.+?)\s+A:\s*(.+)', block, re.S)
            if not m:
                continue
            score = SequenceMatcher(None, q, _norm(m.group(1))).ratio()
            if score > best_score:
                best_score, best_answer = score, m.group(2).strip()
    if best_score < 0.85:
        best_answer = ''
    return {'answer': best_answer, 'similarity': round(best_score, 3)}
