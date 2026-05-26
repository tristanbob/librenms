<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Tests\TestCase;

final class GraphSettingsTranslationTest extends TestCase
{
    public function testGraphModernizationSettingsHaveLabelsAndHelp(): void
    {
        $settings = include base_path('lang/en/settings.php');

        foreach ($this->modernizationSettings() as $setting) {
            $translation = $this->translationFor($settings['settings'], $setting);

            $this->assertNotEmpty($translation['description'] ?? null, "$setting is missing a label");
            $this->assertNotEmpty($translation['help'] ?? null, "$setting is missing question-mark help text");
        }
    }

    public function testVictoriaMetricsGraphReadsRequireWritesAndAreSecondSetting(): void
    {
        $definitions = json_decode(
            file_get_contents(resource_path('definitions/config_definitions.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $setting = $definitions['config']['victoriametrics.query_enabled'];

        $this->assertSame(2, $setting['order']);
        $this->assertSame([
            'setting' => 'victoriametrics.enable',
            'operator' => 'equals',
            'value' => true,
        ], $setting['when']);
    }

    /**
     * @return list<string>
     */
    private function modernizationSettings(): array
    {
        return [
            'graphs.renderer',
            'victoriametrics.enable',
            'victoriametrics.write_mode',
            'victoriametrics.write_host',
            'victoriametrics.write_port',
            'victoriametrics.write_path',
            'victoriametrics.timeout',
            'victoriametrics.batch_size',
            'victoriametrics.verify_ssl',
            'victoriametrics.debug',
            'victoriametrics.query_enabled',
            'victoriametrics.query_url',
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, string>
     */
    private function translationFor(array $settings, string $setting): array
    {
        $current = $settings;
        foreach (explode('.', $setting) as $part) {
            $this->assertIsArray($current);
            $this->assertArrayHasKey($part, $current, "$setting translation is missing");
            $current = $current[$part];
        }

        $this->assertIsArray($current);

        return $current;
    }
}
