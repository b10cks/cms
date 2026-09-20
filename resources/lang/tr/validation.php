<?php

return [
    'backup' => [
        'name_required' => 'Yedek adı gerekli.',
        'recipients_required' => 'En az bir alıcı e-posta adresi gerekli.',
        'invalid_email' => 'Lütfen geçerli bir e-posta adresi girin.',
        'expires_after_now' => 'Son kullanma tarihi gelecekte olmalıdır.',
	],
    'block_template' => [
        'color_regex' => 'Renk, geçerli bir onaltılık renk kodu olmalıdır (örn. #FF5733).',
        'name_required' => 'Şablon adı gerekli.',
        'content_required' => 'Şablon içeriği gerekli.',
    ],
    'block_version' => [
        'commit_message_required' => 'İşlem mesajı (commit message) gerekli.',
        'commit_message_max' => 'İşlem mesajı 500 karakterden uzun olamaz.',
    ],
    'blueprint' => [
        'name_required' => 'Şablon adı gerekli.',
        'color_invalid' => 'Renk, geçerli bir onaltılık renk kodu olmalıdır (örn. #FF5733).',
        'source_space_invalid' => 'Seçilen kaynak alan bulunamadı.',
        'source_space_not_ready' => 'Seçilen kaynak alan henüz kopyalanmaya hazır değil.',
        'tables_invalid' => 'Seçilen bir veya daha fazla tablo şablonlar için desteklenmiyor.',
        'invalid' => 'Seçilen şablon kullanılamıyor.',
        'delete_failed' => 'Alan şablonu silinirken bir hata oluştu.',
    ],
];
