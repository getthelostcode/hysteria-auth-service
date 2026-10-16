<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\OnlineTracker;
use App\Services\TrafficService;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected static string $view = 'filament.resources.user-resource.view-user';

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\Action::make('back')->label('返回列表')->url(UserResource::getUrl('index')),
        ];
    }

    protected function getViewData(): array
    {
        $user = $this->getRecord();
        $online = app(OnlineTracker::class);
        $traffic = app(TrafficService::class);

        return [
            'user' => $user,
            'devices' => $online->devices($user->username),
            'pending' => $traffic->pending($user->username),
            'daily' => $user->dailyTraffic()->orderByDesc('day')->limit(30)->get(),
            'reports' => $user->trafficReports()->orderByDesc('id')->limit(20)->get(),
            'human' => fn (int $bytes): string => UserResource::human($bytes),
        ];
    }
}
