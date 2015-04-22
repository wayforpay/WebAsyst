<?php
return array(
    'merchant_account'    => array(
        'value'        => 'test_merch_n1',
        'title'        => 'ID Мерчанта',
        'description'  => 'Идентификатор мерчанта в системе WayForPay',
        'control_type' => waHtmlControl::INPUT,
    ),
    'secret_key' => array(
        'value'        => 'flk3409refn54t54t*FNJRET',
        'title'        => 'Секретный ключ',
        'description'  => 'Ваш ключ полученное от системы WayForPay.',
        'control_type' => waHtmlControl::INPUT,
    ),
    'language'           => array(
        'value'        => 'RU',
        'title'        => 'Язык',
        'description'  => 'Язык страницы оплаты WayForPay.',
        'control_type' => waHtmlControl::SELECT,
        'options'      => array(
            'RU' => 'Русский',
            'UA' => 'Українська',
            'EN' => 'English'
        ),
    )
);