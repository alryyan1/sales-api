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

		$this->command?->info('App settings seeded.');
	}
}


