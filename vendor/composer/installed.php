<?php return array(
    'root' => array(
        'name' => 'wylly/wp-vms',
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
        'wylly/wp-vms' => array(
            'pretty_version' => '1.0.0',
            'version' => '1.0.0.0',
            'reference' => null,
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'yahnis-elsts/plugin-update-checker' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '8add8143a274c47cafed48512e1259a2be859837',
            'type' => 'library',
            'install_path' => __DIR__ . '/../yahnis-elsts/plugin-update-checker',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
    ),
);
