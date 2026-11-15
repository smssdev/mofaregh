<?php

namespace App\Enums;

/**
 * The five roles of docs/sprints.md (Sprint 1). A user has exactly one role.
 */
enum Role: string
{
    case Admin = 'admin';
    case ProjectManager = 'project_manager';
    case Transcriber = 'transcriber';
    case LinguisticReviewer = 'linguistic_reviewer';
    case ShariaChecker = 'sharia_checker';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'مدير النظام',
            self::ProjectManager => 'مدير مشروع',
            self::Transcriber => 'مفرِّغ',
            self::LinguisticReviewer => 'مراجع لغوي',
            self::ShariaChecker => 'مدقق شرعي',
        };
    }

    /** Roles that work inside projects as members (as opposed to running them). */
    public function isProjectMemberRole(): bool
    {
        return in_array($this, [self::Transcriber, self::LinguisticReviewer, self::ShariaChecker], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
