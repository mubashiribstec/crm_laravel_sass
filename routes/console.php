<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;

    Artisan::command('inspire', function () {
        $this->comment(Inspiring::quote());
    })->purpose('Display an inspiring quote')->hourly();

    Schedule::command('emails:send-bulk')
                ->everyMinute()
                ->withoutOverlapping()
                ->onSuccess(function () {
                    Log::info('SendBulkEmails command ran successfully.');
                })
                ->onFailure(function () {
                    Log::error('SendBulkEmails command failed.');
                });
    
    Schedule::command('sms:send-bulk')
                ->everyMinute()
                ->withoutOverlapping()
                ->onSuccess(function () {
                    Log::info('SendSms command ran successfully.');
                })
                ->onFailure(function () {
                    Log::error('SendSms command failed.');
                });

    Schedule::command('app:update-available-job-status')
                ->dailyAt('22:00')
                ->withoutOverlapping()
                ->onSuccess(function () {
                    Log::info('Update to the job availability command ran successfully.');
                })
                ->onFailure(function () {
                    Log::error('Update to the job availability command failed.');
                });

    Schedule::command('attachments:purge-deleted')
                ->hourly()
                ->withoutOverlapping();
