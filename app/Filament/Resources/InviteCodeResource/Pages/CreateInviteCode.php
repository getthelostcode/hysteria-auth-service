<?php

declare(strict_types=1);

namespace App\Filament\Resources\InviteCodeResource\Pages;

use App\Filament\Resources\InviteCodeResource;
use App\Models\InviteCode;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInviteCode extends CreateRecord
{
    protected static string $resource = InviteCodeResource::class;

    /** 邀请码本身由系统生成，表单只填限制。 */
    protected function handleRecordCreation(array $data): Model
    {
        return InviteCode::generate(
            (int) ($data['max_uses'] ?? 1),
            isset($data['quota_bytes']) ? (int) $data['quota_bytes'] : null,
            isset($data['valid_days']) ? (int) $data['valid_days'] : null,
            (string) ($data['note'] ?? ''),
            (int) auth('admin')->id(),
        )->tap(function (InviteCode $invite) use ($data): void {
            if (! empty($data['expires_at'])) {
                $invite->forceFill(['expires_at' => $data['expires_at']])->save();
            }
        });
    }
}
