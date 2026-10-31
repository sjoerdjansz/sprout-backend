<?php

namespace App\Actions;

use App\Models\UserSetting;

/**
 * Saves the preferences a learner changed on the settings page. Only the given fields change, because
 * each tab saves its own.
 */
class UpdateSettings
{
    /**
     * @param  array<string, mixed>  $changes  validated fields of UpdateSettingsRequest
     */
    public function handle(UserSetting $settings, array $changes): UserSetting
    {
        $settings->update($changes);

        return $settings;
    }
}
