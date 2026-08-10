<?php

define('ABSPATH', __DIR__ . '/');

$wasmer_migrate_test_random_values = [0, 25, 26, 35, 1, 24, 27, 34];

function wp_rand($min, $max)
{
    global $wasmer_migrate_test_random_values;

    $value = array_shift($wasmer_migrate_test_random_values);
    if (!is_int($value) || $value < $min || $value > $max) {
        throw new RuntimeException('Unexpected random value request.');
    }
    return $value;
}

function sanitize_title($value)
{
    $value = strtolower((string) $value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim((string) $value, '-');
}

function sanitize_text_field($value)
{
    return trim((string) $value);
}

function esc_url_raw($value)
{
    return (string) $value;
}

function sanitize_key($value)
{
    return strtolower((string) $value);
}

function wp_strip_all_tags($value)
{
    return strip_tags((string) $value);
}

function get_bloginfo($field)
{
    return $field === 'name' ? 'Test Site' : '';
}

function get_option($key)
{
    return $key === 'admin_email' ? 'admin@example.com' : '';
}

class WP_User
{
}

function wp_get_current_user()
{
    return null;
}

function sanitize_email($value)
{
    return (string) $value;
}

function get_user_by($field, $value)
{
    return false;
}

function get_users($args)
{
    return [];
}

function wp_generate_password($length, $special_chars = true, $extra_special_chars = false)
{
    return str_repeat('p', $length);
}

function wasmer_migrate_test_fail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../auto-app.php';

$options = wasmer_migrate_auto_options([]);
if ($options['app_name'] !== 'wordpress-az09by18') {
    wasmer_migrate_test_fail('The default app name was not an eight-character randomized WordPress name.');
}
if (!preg_match('/^wordpress-[a-z0-9]{8}$/', $options['app_name'])) {
    wasmer_migrate_test_fail('The default app name did not match the expected format.');
}

$custom_options = wasmer_migrate_auto_options(['app_name' => 'My Custom Site']);
if ($custom_options['app_name'] !== 'my-custom-site') {
    wasmer_migrate_test_fail('An explicit app name was not preserved.');
}

echo "ok\n";
