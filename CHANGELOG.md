# Changelog

All notable changes to `nativephp-mobile-splashscreen` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v1.2.1 - 2026-06-12

### Fixed
- iOS builds failed with `Unable to find module dependency: 'Lottie'` because the `lottie-spm` Swift package was never declared — the generated `SplashView.swift` imports `Lottie`, so the build only succeeded when the package already happened to be present in the Xcode project. It is now declared in `nativephp.json` and added on every build.

**Full Changelog**: https://github.com/S2BR/nativephp-mobile-splashscreen/compare/v1.2.0...v1.2.1

## v1.2.0 - 2026-06-10

### Added
- Progress bar fill direction — set `ltr` (default, fills left→right) or `rtl` (fills right→left) via the new `MOBILE_SPLASHSCREEN_PROGRESS_BAR_DIRECTION` env variable / `progress_bar.direction` config key
- `progress_bar_direction` is overridable per entry in both the static (build-time) and dynamic (remote) schedules
- Validation of the progress bar direction value via `MobileSplashscreen::validate()`
- Recurring schedule dates — schedule entries now accept `MM-DD` (recurs every year) in addition to `YYYY-MM-DD` (specific year). When both a full-date and a recurring entry match the same day, the full date takes precedence. Works for both static and dynamic schedules
- `min_version` declared in `nativephp.json` — Android 26 (Android 8) and iOS 18.0, matching the NativePHP v3 supported range

### Changed
- Plugin registration docs now lead with the `php artisan native:plugin:register` command (manual `NativeServiceProvider` editing kept as an alternative)

### Fixed
- Dynamic remote schedule now passes through `progress_bar` and `progress_bar_color` per-entry overrides — previously documented as supported but dropped during sync
- Static schedule `MM-DD` dates were documented but never matched (the on-device matcher compared them against a full `YYYY-MM-DD` date); recurring dates now work as intended

**Full Changelog**: https://github.com/S2BR/nativephp-mobile-splashscreen/compare/v1.1.0...v1.2.0

## v1.1.0 - 2026-06-08

### Added
- 8 bundled, free Lottie example animations in `resources/animations/`, sourced from [LottieFiles](https://lottiefiles.com) with full author attribution
- New `mobile-splashscreen-examples` publish tag — run `php artisan vendor:publish --tag=mobile-splashscreen-examples` to copy the example animations into your app's `resources/animations/`
- "Bundled Example Animations" section in the README, including a credits table linking each animation to its original author and LottieFiles source

### Changed
- Removed the hardcoded `version` field from `composer.json` — the git tag is now the single source of truth for the package version

**Full Changelog**: https://github.com/S2BR/nativephp-mobile-splashscreen/compare/v1.0.0...v1.1.0

## v1.0.0 - 2026-06-02

First public release of the NativePHP Mobile Splashscreen plugin — a fully config-driven splash screen for iOS and Android NativePHP apps, with no manual native code editing required.

### Content
- Lottie animation support (.lottie v2 → v1 automatic conversion)
- Text-only splash screens as an alternative to animations
- Single-play and looping animation modes

### Visual
- Solid color and gradient backgrounds (vertical, horizontal, diagonal)
- Dark mode support with separate animations and background config, or follows system automatically
- App icon overlay with configurable size, position and corner radius
- Progress bar for loading indication

### Exit Transitions
- fade, scale_up, scale_down, slide_up, slide_down, circle_expand, none
- Configurable duration, delay before and after, and origin point (8 positions for circle_expand)

### Scheduling
- Build-time static schedule for seasonal splash screens (Christmas, New Year, etc.)
- Dynamic runtime schedule — download new animations and config remotely without releasing a new build
- Automatic cleanup of stale scheduled files

### Events
- SplashscreenCompleted — fired when the splash finishes
- SplashscreenLoopCompleted — fired on each animation loop iteration

### Platform integration
- Syncs the OS-level launch color to LaunchScreen.storyboard (iOS) and themes.xml (Android)
- Hooks into NativePHP's copy_assets and pre_compile stages — no Xcode or Android Studio changes needed

**Full Changelog**: https://github.com/S2BR/nativephp-mobile-splashscreen/commits/v1.0.0
