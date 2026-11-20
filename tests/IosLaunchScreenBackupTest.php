<?php

/*
 * Xcode syncs the whole NativePHP/ folder into the app bundle, so the
 * LaunchScreen.storyboard backup must live outside it (like Android's
 * nativephp-backups). The hook can't boot in this standalone suite, so these
 * tests lock the paths in place; the bundle contents are verified by a build.
 */

function launchScreenSource(): string
{
    $source = file_get_contents(__DIR__.'/../src/Commands/PreCompileCommand.php');
    preg_match('/function modifyLaunchScreen\(.*?\n    \}\n/s', $source, $match);

    return $match[0];
}

it('keeps the LaunchScreen.storyboard backup outside the synced NativePHP folder', function () {
    expect(launchScreenSource())
        ->toContain("\$backupDir = \$this->buildPath().'/nativephp-backups';")
        ->toContain("\$originalPath = \$backupDir.'/LaunchScreen.storyboard.original';")
        ->not->toContain("\$originalPath = \$path.'.original';");
});

it('moves a backup left next to the storyboard by earlier versions', function () {
    expect(launchScreenSource())
        ->toContain("rename(\$path.'.original', \$originalPath);");
});
