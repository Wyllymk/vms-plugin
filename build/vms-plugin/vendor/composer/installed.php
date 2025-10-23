<?php return array(
    'root' => array(
        'name' => 'wylly/vms-plugin',
        'pretty_version' => '1.0.0',
        'version' => '1.0.0.0',
        'reference' => null,
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        'wylly/vms-plugin' => array(
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
            'reference' => '288f270d8e5afe80114331e53aba0a55709092a1',
            'type' => 'library',
            'install_path' => __DIR__ . '/../yahnis-elsts/plugin-update-checker',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
    ),
);
