<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\AvatarTone;
use App\Support\Initials;
use PHPUnit\Framework\TestCase;

/**
 * A member's placeholder: one letter, on a colour that is theirs.
 */
final class AvatarToneTest extends TestCase
{
    public function testAMemberAlwaysGetsTheSameToneAndItIsInRange(): void
    {
        foreach ([1, 2, 7, 8, 9, 64, 1001] as $id) {
            $tone = AvatarTone::of($id);
            self::assertSame($tone, AvatarTone::of($id));
            self::assertGreaterThanOrEqual(1, $tone);
            self::assertLessThanOrEqual(AvatarTone::COUNT, $tone);
        }
    }

    public function testTheFirstMembersInAnInstanceNeverShareATone(): void
    {
        $tones = array_map(AvatarTone::of(...), range(1, AvatarTone::COUNT));

        self::assertSame(range(1, AvatarTone::COUNT), $tones);
        self::assertSame(AvatarTone::of(1), AvatarTone::of(AvatarTone::COUNT + 1));
    }

    public function testNoMemberHasNoTone(): void
    {
        self::assertNull(AvatarTone::of(null));
        self::assertNull(AvatarTone::of(0));
    }

    /**
     * The tones are theme tokens; every one AvatarTone can pick exists in
     * both base themes, and there are none it can never pick.
     */
    public function testEveryToneIsDefinedInTheThemeTokens(): void
    {
        $tokens = json_decode(
            (string) file_get_contents(__DIR__ . '/../../assets/theme/tokens.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach (['light', 'dark'] as $theme) {
            $defined = preg_grep('/^avatar-\d+$/', array_keys($tokens['base'][$theme]));
            self::assertCount(AvatarTone::COUNT, (array) $defined, $theme);

            for ($tone = 1; $tone <= AvatarTone::COUNT; $tone++) {
                self::assertArrayHasKey('avatar-' . $tone, $tokens['base'][$theme]);
                self::assertArrayHasKey('avatar-' . $tone . '-ink', $tokens['base'][$theme]);
            }
        }
    }

    public function testThePlaceholderLetterIsTheFirstNamesInitial(): void
    {
        self::assertSame('S', Initials::first('Sam Taylor'));
        self::assertSame('É', Initials::first('  émile Zola'));
        self::assertSame('M', Initials::first('Mo'));
        self::assertSame('?', Initials::first('   '));
        self::assertSame('?', Initials::first(null));
    }
}
