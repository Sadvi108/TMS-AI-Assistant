<?php

declare(strict_types=1);

/*
 * Sidebar topics and their suggested questions.
 * "featured" picks the cards on the first screen: [topic id, question index].
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
                ['en' => 'Can my club take part in more than one tournament?', 'ms' => 'Bolehkah kelab saya menyertai lebih daripada satu kejohanan?'],
            ],
        ],
        [
            'id' => 'player',
            'icon' => 'user-plus',
            'label' => ['en' => 'Player registration', 'ms' => 'Pendaftaran pemain'],
            'short' => ['en' => 'Player registration', 'ms' => 'Pendaftaran pemain'],
            'questions' => [
                ['en' => 'What documents do I need to register a player?', 'ms' => 'Apakah dokumen yang diperlukan untuk mendaftar pemain?'],
                ['en' => 'How do I register a player?', 'ms' => 'Bagaimanakah cara mendaftar pemain?'],
                ['en' => 'Can a player be registered with two clubs in one season?', 'ms' => 'Bolehkah seorang pemain didaftarkan dengan dua kelab dalam satu musim?'],
                ['en' => "Why can't I register my player?", 'ms' => 'Kenapa saya tidak dapat mendaftar pemain saya?'],
            ],
        ],
        [
            'id' => 'payments',
            'icon' => 'credit-card',
            'label' => ['en' => 'Payments', 'ms' => 'Pembayaran'],
            'short' => ['en' => 'Payments', 'ms' => 'Pembayaran'],
            'questions' => [
                ['en' => 'How much is the club registration fee?', 'ms' => 'Berapakah yuran pendaftaran kelab?'],
                ['en' => 'Why is my payment still pending?', 'ms' => 'Kenapa pembayaran saya masih belum disahkan?'],
                ['en' => 'Can I cancel my registration and get a refund?', 'ms' => 'Bolehkah saya membatalkan pendaftaran dan mendapat bayaran balik?'],
            ],
        ],
        [
            'id' => 'account',
            'icon' => 'lock',
            'label' => ['en' => 'Account & login', 'ms' => 'Akaun & log masuk'],
            'short' => ['en' => 'Account & login', 'ms' => 'Akaun & log masuk'],
            'questions' => [
                ['en' => 'I forgot my password. How do I reset it?', 'ms' => 'Saya terlupa kata laluan. Bagaimanakah cara menetapkannya semula?'],
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
