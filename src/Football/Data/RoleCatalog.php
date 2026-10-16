<?php

declare(strict_types=1);

namespace App\Football\Data;

/**
 * Finer roles than the source's position groups (GK, DF, MF, FW): "midfielder" covers wingers
 * and holding midfielders alike, who do different jobs and should be compared with their own
 * kind.
 *
 * Roles come from the EA SPORTS FC position in the Top-5 dataset: the player's position in
 * his club's line-up when he has one, otherwise his best position. Players without either are
 * inferred from their statistics (RoleInference).
 */
final class RoleCatalog
{
    /** Role => label, plural label and the broad position groups the role is compatible with. */
    public const ROLES = [
        'ST' => ['label' => 'Striker', 'plural' => 'strikers', 'groups' => ['FW', 'MF']],
        'W' => ['label' => 'Winger', 'plural' => 'wingers', 'groups' => ['FW', 'MF']],
        'AM' => ['label' => 'Attacking midfielder', 'plural' => 'attacking midfielders', 'groups' => ['MF', 'FW']],
        'CM' => ['label' => 'Central midfielder', 'plural' => 'central midfielders', 'groups' => ['MF']],
        'DM' => ['label' => 'Defensive midfielder', 'plural' => 'defensive midfielders', 'groups' => ['MF', 'DF']],
        'FB' => ['label' => 'Full-back', 'plural' => 'full-backs', 'groups' => ['DF', 'MF']],
        'CB' => ['label' => 'Centre-back', 'plural' => 'centre-backs', 'groups' => ['DF']],
        'GK' => ['label' => 'Goalkeeper', 'plural' => 'goalkeepers', 'groups' => ['GK']],
    ];

    /** The lines of a team, front to back, with the roles they hold. */
    public const LINES = [
        'FW' => ['label' => 'Forwards', 'roles' => ['ST', 'W']],
        'MF' => ['label' => 'Midfield', 'roles' => ['AM', 'CM', 'DM']],
        'DF' => ['label' => 'Defence', 'roles' => ['FB', 'CB']],
        'GK' => ['label' => 'Goalkeepers', 'roles' => ['GK']],
    ];

    /** EA SPORTS FC position => role. */
    private const EA_FC = [
        'ST' => 'ST', 'CF' => 'ST', 'LS' => 'ST', 'RS' => 'ST', 'LF' => 'ST', 'RF' => 'ST',
        'LW' => 'W', 'RW' => 'W', 'LM' => 'W', 'RM' => 'W',
        'CAM' => 'AM', 'LAM' => 'AM', 'RAM' => 'AM',
        'CM' => 'CM', 'LCM' => 'CM', 'RCM' => 'CM',
        'CDM' => 'DM', 'LDM' => 'DM', 'RDM' => 'DM',
        'LB' => 'FB', 'RB' => 'FB', 'LWB' => 'FB', 'RWB' => 'FB',
        'CB' => 'CB', 'LCB' => 'CB', 'RCB' => 'CB',
        'GK' => 'GK',
    ];

    /** Where a player's role came from. */
    public const SOURCE_CLUB_POSITION = 'ea_fc_club';
    public const SOURCE_BEST_POSITION = 'ea_fc_best';
    public const SOURCE_INFERRED = 'inferred';
    /** No EA FC position and no statistics to infer from: the group's most common role. */
    public const SOURCE_DEFAULT = 'default';
    /** Goalkeepers without an EA FC position: the source's position group. */
    public const SOURCE_POSITION = 'position';

    /**
     * The role from EA SPORTS FC positions: the club line-up position (not substitute or
     * reserve), otherwise the best position. In a back three the line-up names the wing-backs
     * LM and RM: a wide midfielder whose best position is full-back is a full-back.
     *
     * @return array{0: string, 1: string}|null role and source
     */
    public static function fromEaFc(?string $clubPosition, ?string $bestPosition): ?array
    {
        if ($clubPosition !== null && isset(self::EA_FC[$clubPosition])) {
            $role = self::EA_FC[$clubPosition];
            if (in_array($clubPosition, ['LM', 'RM'], true) && $bestPosition !== null && (self::EA_FC[$bestPosition] ?? null) === 'FB') {
                $role = 'FB';
            }

            return [$role, self::SOURCE_CLUB_POSITION];
        }
        if ($bestPosition !== null && isset(self::EA_FC[$bestPosition])) {
            return [self::EA_FC[$bestPosition], self::SOURCE_BEST_POSITION];
        }

        return null;
    }

    /**
     * The EA FC role checked against the source's position group: a role the group cannot play
     * is not used (null: infer it), except a wide midfielder or winger listed as a defender,
     * who is a wing-back, so a full-back.
     */
    public static function forGroup(string $role, ?string $group): ?string
    {
        if ($group === null || in_array($role, self::rolesFor($group), true)) {
            return $role;
        }

        return $group === 'DF' && $role === 'W' ? 'FB' : null;
    }

    /** @return list<string> roles compatible with a broad position group */
    public static function rolesFor(string $group): array
    {
        return array_keys(array_filter(self::ROLES, static fn (array $r) => in_array($group, $r['groups'], true)));
    }

    /** The line a role plays in (FW, MF, DF, GK). */
    public static function line(string $role): ?string
    {
        foreach (self::LINES as $line => $info) {
            if (in_array($role, $info['roles'], true)) {
                return $line;
            }
        }

        return null;
    }
}
