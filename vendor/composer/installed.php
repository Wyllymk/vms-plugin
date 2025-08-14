<?php return array(
    'root' => array(
        'name' => 'wylly/cyber-wakili-plugin',
        'pretty_version' => '1.0.0',
        'version' => '1.0.0.0',
        'reference' => null,
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'cmb2/cmb2' => array(
            'pretty_version' => 'v2.11.0',
            'version' => '2.11.0.0',
            'reference' => '2847828b5cce1b48d09427ee13e6f7c752704468',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../cmb2/cmb2',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'firebase/php-jwt' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => '953b2c88bb445b7e3bb82a5141928f13d7343afd',
            'type' => 'library',
            'install_path' => __DIR__ . '/../firebase/php-jwt',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'wylly/cyber-wakili-plugin' => array(
            'pretty_version' => '1.0.0',
            'version' => '1.0.0.0',
            'reference' => null,
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
