<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'manager' => MemberResource::make($this->whenLoaded('manager')),
            'members' => MemberResource::collection($this->whenLoaded('members')),
            'audio_files_count' => $this->whenCounted('audioFiles'),
            'can_manage' => $this->isManageableBy($request->user()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
