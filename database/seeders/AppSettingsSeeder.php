<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AppSetting;
use App\Services\SettingsService;

class AppSettingsSeeder extends Seeder
{
	public function run(): void
	{
		$service = new SettingsService();
		$defaults = $service->defaultValues();
		$types = $service->managedKeysWithTypes();

		foreach ($types as $key => $type) {
			$value = $defaults[$key] ?? null;
			$stored = match ($type) {
				'bool' => $value ? 'true' : 'false',
				'int' => (string) ((int) ($value ?? 0)),
				'float' => (string) ((float) ($value ?? 0)),
				default => $value === null ? null : (string) $value,
			};

			AppSetting::updateOrCreate([
				'key' => $key,
			], [
				'value' => $stored,
			]);
		}

		// Override with LifeCare Medical Equipment Trading Enterprises information
		$lifeCareSettings = [
			'company_name' => 'LifeCare Medical Equipment Trading Enterprises',
			'company_phone' => '+249 1230 56130',
			'company_phone_2' => '+249 1247 81028',
			'company_email' => 'motasimceo@lifcaresd.com',
			'company_address' => "مواقعنا:\n• ولاية الخرطوم – أم درمان – الثورة – الحارة 8\n• ولاية البحر الأحمر – بورتسودان – حي المطار",
		];

		foreach ($lifeCareSettings as $key => $value) {
			AppSetting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
		}

		$this->command?->info('App settings seeded with LifeCare company information.');
	}
}
