<?php
// Dependency-free self-test of the topic order (run: php Tests/selftest.php).
declare(strict_types=1);
require __DIR__.'/../Service/TopicStore.php';
use MauticPlugin\KopexaPreferencesCenterBundle\Service\TopicStore;

$fail  = static function (string $m): never { fwrite(STDERR, "FAIL $m\n"); exit(1); };
$store = new class extends TopicStore {
    public function __construct() {}
    public function all(): array
    {
        return [
            1 => ['visible' => true, 'position' => 20, 'text' => null],
            2 => ['visible' => false, 'position' => 1, 'text' => null],
            3 => ['visible' => true, 'position' => 10, 'text' => 'x'],
        ];
    }
};
$titles = [1 => 'Webinars', 2 => 'Internal', 3 => 'Updates', 4 => 'beta', 5 => 'Alpha'];
($got = $store->order($titles)) === [3, 1, 5, 4] || $fail('order: '.json_encode($got));
echo "ok   positioned topics first, then the others by name (natural, case-insensitive); hidden ones left out\n";
($got = $store->order($titles, true)) === [2, 3, 1, 5, 4] || $fail('order with hidden: '.json_encode($got));
echo "ok   with hidden topics on request\n";
$store->get(99) === TopicStore::defaults() && TopicStore::defaults()['visible'] || $fail('defaults');
echo "ok   categories without settings are shown (Mautic's behaviour)\n";
