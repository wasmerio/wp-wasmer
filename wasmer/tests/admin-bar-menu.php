<?php

define('ABSPATH', __DIR__ . '/');
define('WASMER_APP_ID', 'abc');
define('WASMER_PERISHABLE_TIMESTAMP', '');

$wasmer_test_logged_in = false;
$wasmer_test_is_admin = false;

function is_user_logged_in()
{
    global $wasmer_test_logged_in;
    return $wasmer_test_logged_in;
}

function is_admin()
{
    global $wasmer_test_is_admin;
    return $wasmer_test_is_admin;
}

function wasmer_base_url()
{
    return 'http://wasmer.xyz';
}

class Wasmer_Test_Admin_Bar
{
    public $menus = [];

    public function add_menu($menu)
    {
        $this->menus[] = $menu;
    }
}

function wasmer_test_top_bar_menus($logged_in, $is_admin)
{
    global $wasmer_test_logged_in, $wasmer_test_is_admin;

    $wasmer_test_logged_in = $logged_in;
    $wasmer_test_is_admin = $is_admin;

    $admin_bar = new Wasmer_Test_Admin_Bar();
    wasmer_add_top_bar_menu($admin_bar);

    return $admin_bar->menus;
}

function wasmer_assert_count($expected, $actual, $message)
{
    $count = count($actual);

    if ($count !== $expected) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected menu count: ' . $expected . PHP_EOL);
        fwrite(STDERR, 'Actual menu count: ' . $count . PHP_EOL);
        exit(1);
    }
}

require_once __DIR__ . '/../admin.php';

wasmer_assert_count(
    0,
    wasmer_test_top_bar_menus(false, false),
    'Wasmer admin bar menu should not render for logged-out frontend visitors.'
);

wasmer_assert_count(
    0,
    wasmer_test_top_bar_menus(false, true),
    'Wasmer admin bar menu should not render for logged-out admin requests.'
);

wasmer_assert_count(
    0,
    wasmer_test_top_bar_menus(true, false),
    'Wasmer admin bar menu should not render on frontend requests.'
);

wasmer_assert_count(
    2,
    wasmer_test_top_bar_menus(true, true),
    'Wasmer admin bar menu should render for logged-in admin requests.'
);

echo "ok\n";
