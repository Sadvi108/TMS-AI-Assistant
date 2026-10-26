<?php

declare(strict_types=1);

/*
 * Sidebar topics and their suggested questions.
 * "featured" picks the cards on the first screen: [topic id, question index].
 *
 * Every English question is worded exactly as in the FAQ knowledge base
 * (tools/faq.csv), so clicking it returns TMS's approved answer as Verified.
 * tools/faq-check.php --suggestions checks that this stays true.
 */
return [
    'topics' => [
        [
            'id' => 'club',
            'icon' => 'users',
            'label' => ['en' => 'Club & team registration', 'ms' => 'Pendaftaran kelab & pasukan'],
            'short' => ['en' => 'Club & team', 'ms' => 'Kelab & pasukan'],
            'questions' => [
                ['en' => 'How do I register a new club?', 'ms' => 'Bagaimanakah cara mendaftar kelab baharu?'],
                ['en' => 'My club is already registered. What should I do?', 'ms' => 'Kelab saya sudah berdaftar. Apa yang perlu saya lakukan?'],
                ['en' => 'Can my club participate in multiple tournaments?', 'ms' => 'Bolehkah kelab saya menyertai beberapa kejohanan?'],
            ],
        ],
        [
            'id' => 'player',
            'icon' => 'user-plus',
            'label' => ['en' => 'Player registration', 'ms' => 'Pendaftaran pemain'],
            'short' => ['en' => 'Player registration', 'ms' => 'Pendaftaran pemain'],
            'questions' => [
                ['en' => 'How do I register a player?', 'ms' => 'Bagaimanakah cara mendaftar pemain?'],
                ['en' => "Why can't I register my player?", 'ms' => 'Kenapa saya tidak dapat mendaftar pemain saya?'],
                ['en' => 'Can one player register for multiple events?', 'ms' => 'Bolehkah seorang pemain mendaftar untuk beberapa acara?'],
                ['en' => "Why is my player's name missing?", 'ms' => 'Kenapa nama pemain saya tiada?'],
            ],
        ],
        [
            'id' => 'payments',
            'icon' => 'credit-card',
            'label' => ['en' => 'Payments', 'ms' => 'Pembayaran'],
            'short' => ['en' => 'Payments', 'ms' => 'Pembayaran'],
            'questions' => [
                ['en' => 'Why is my payment still pending?', 'ms' => 'Kenapa pembayaran saya masih belum disahkan?'],
                ['en' => 'How do I check my registration fee?', 'ms' => 'Bagaimanakah cara menyemak yuran pendaftaran saya?'],
                ['en' => 'What should I do if I paid but my registration is not confirmed?', 'ms' => 'Apa yang perlu saya lakukan jika saya sudah membayar tetapi pendaftaran belum disahkan?'],
                ['en' => 'Can I cancel my registration and get a refund?', 'ms' => 'Bolehkah saya membatalkan pendaftaran dan mendapat bayaran balik?'],
            ],
        ],
        [
            'id' => 'account',
            'icon' => 'lock',
            'label' => ['en' => 'Account & login', 'ms' => 'Akaun & log masuk'],
            'short' => ['en' => 'Account & login', 'ms' => 'Akaun & log masuk'],
            'questions' => [
                ['en' => 'I forgot my login password. What should I do?', 'ms' => 'Saya terlupa kata laluan log masuk. Apa yang perlu saya lakukan?'],
                ['en' => 'Do I need an account?', 'ms' => 'Adakah saya perlukan akaun?'],
            ],
        ],
    ],
    'featured' => [
        ['player', 0],
        ['club', 0],
        ['payments', 0],
        ['account', 0],
    ],
];
