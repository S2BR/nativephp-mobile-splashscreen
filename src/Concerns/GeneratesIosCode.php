<?php

namespace S2BR\MobileSplashscreen\Concerns;

trait GeneratesIosCode
{
    protected function buildIosSplashView(array $config, string $animationName): string
    {
        $isAnimation = $config['content'] === 'animation';
        $isLoop = $isAnimation && ($config['animation']['loop'] ?? true);
        $showIcon = $config['icon']['show'] ?? false;
        $progressBarEnabled = $config['progress_bar']['enabled'] ?? false;
        $onLoop = $isLoop && ($config['events']['on_loop'] ?? false);
        $onComplete = ! $isLoop && ($config['events']['on_complete'] ?? true);

        $themeMode = $config['theme']['mode'] ?? 'auto';
        $usesColorScheme = $themeMode === 'auto';
        $hasDarkBg = $usesColorScheme && ($config['theme']['dark']['background']['type'] ?? null) !== null;
        $hasDarkAnim = $usesColorScheme && ($config['theme']['dark']['animation_path'] ?? null) !== null;
        $darkAnimName = $hasDarkAnim
            ? pathinfo($config['theme']['dark']['animation_path'], PATHINFO_FILENAME)
            : null;

        $schedule = $this->iosParseSchedule($config);
        $hasSchedule = ! empty($schedule);

        $transition = $config['transition_out'] ?? [];
        $transitionType = $transition['type'] ?? 'none';
        $transitionDurationMs = (int) ($transition['duration'] ?? 400);
        $transitionDurationSecs = number_format($transitionDurationMs / 1000, 2, '.', '');
        $transitionOrigin = $transition['origin'] ?? 'center';

        $fadeIn = number_format(($config['timing']['fade_in'] ?? 600) / 1000, 2, '.', '');
        $delayBefore = number_format(($config['timing']['delay_before'] ?? 0) / 1000, 2, '.', '');
        $delayAfter = number_format(($config['timing']['delay_after'] ?? 0) / 1000, 2, '.', '');

        $size = number_format(max(0.1, min(1.0, $config['animation']['size'] ?? 0.8)), 2, '.', '');
        $position = $config['animation']['position'] ?? 'center';

        $iconSize = number_format(max(0.1, min(0.5, $config['icon']['size'] ?? 0.2)), 2, '.', '');
        $iconPosition = $config['icon']['position'] ?? 'bottom';
        $iconRadius = number_format(max(0.0, min(0.5, $config['icon']['corner_radius'] ?? 0.22)), 2, '.', '');

        $swiftOnComplete = $onComplete ? 'true' : 'false';
        $swiftOnLoop = $onLoop ? 'true' : 'false';
        $swiftLoop = $isLoop ? 'true' : 'false';
        $swiftShowIcon = $showIcon ? 'true' : 'false';

        // If theme.mode == 'dark', resolve config from dark section
        if ($themeMode === 'dark') {
            $activeBg = $config['theme']['dark']['background']['type'] !== null
                ? $config['theme']['dark']['background']
                : $config['background'];
            $activeAnimName = ($config['theme']['dark']['animation_path'] ?? null)
                ? pathinfo($config['theme']['dark']['animation_path'], PATHINFO_FILENAME)
                : $animationName;
        } else {
            $activeBg = $config['background'];
            $activeAnimName = $animationName;
        }

        $lines = [];

        // ── Imports ──────────────────────────────────────────────────────────
        $lines[] = 'import SwiftUI';
        if ($isAnimation) {
            $lines[] = 'import Lottie';
        }
        $lines[] = '';

        // ── Color(hexString:) extension ───────────────────────────────────────
        $lines[] = 'private extension Color {';
        $lines[] = '    init(hexString: String) {';
        $lines[] = '        let hex = hexString.trimmingCharacters(in: CharacterSet(charactersIn: "#"))';
        $lines[] = '        let scanner = Scanner(string: hex)';
        $lines[] = '        var rgb: UInt64 = 0';
        $lines[] = '        scanner.scanHexInt64(&rgb)';
        $lines[] = '        let r = Double((rgb >> 16) & 0xFF) / 255';
        $lines[] = '        let g = Double((rgb >> 8) & 0xFF) / 255';
        $lines[] = '        let b = Double(rgb & 0xFF) / 255';
        $lines[] = '        self.init(red: r, green: g, blue: b)';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        $lines[] = 'struct SplashView: View {';

        // ── Dynamic schedule loader (runtime, from sync command) ───────────────
        $lines[] = '    private static let dynamic: [String: Any]? = {';
        $lines[] = '        guard let support = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first else { return nil }';
        $lines[] = '        let url = support.appendingPathComponent("storage/app/splashscreen/schedule-local.json")';
        $lines[] = '        guard let data = try? Data(contentsOf: url),';
        $lines[] = '              let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any],';
        $lines[] = '              let schedule = root["schedule"] as? [[String: Any]] else { return nil }';
        $lines = array_merge($lines, $this->iosScheduleMatchLines());
        $lines[] = '    }()';
        $lines[] = '';

        // ── Static schedule loader (build-time, embedded JSON) ─────────────────
        // Supports all per-entry overrides — same priority as dynamic but lower.
        if ($hasSchedule) {
            $embeddedEntries = $this->iosEmbedScheduleEntries($schedule);
            $json = json_encode($embeddedEntries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $escapedJson = str_replace('"', '\\"', str_replace('\\', '\\\\', $json));

            $lines[] = '    private static let staticEntry: [String: Any]? = {';
            $lines[] = '        guard let data = "'.$escapedJson.'".data(using: .utf8),';
            $lines[] = '              let schedule = try? JSONSerialization.jsonObject(with: data) as? [[String: Any]] else { return nil }';
            $lines = array_merge($lines, $this->iosScheduleMatchLines());
            $lines[] = '    }()';
            $lines[] = '';
        }

        // ── State variables — always emit all ─────────────────────────────────
        $lines[] = '    @ObservedObject private var appState = AppState.shared';
        $lines[] = '    @State private var contentOpacity: Double = 0';
        $lines[] = '    @State private var webViewReady = false';
        $lines[] = '    @State private var exitTriggered = false';
        $lines[] = '    @State private var animationCompleted = false';
        $lines[] = '    @State private var loopCount: Int = 0';
        $lines[] = '    @State private var exitOpacity: Double = 1';
        $lines[] = '    @State private var exitScale: CGFloat = 1';
        $lines[] = '    @State private var exitOffset: CGFloat = 0';
        $lines[] = '    @State private var circleScale: CGFloat = 0.001';
        if ($usesColorScheme) {
            $lines[] = '    @Environment(\\.colorScheme) var colorScheme';
        }
        $lines[] = '    @State private var loadProgress: Double = 0';
        $lines[] = '    @State private var showProgressBar = false';
        $lines[] = '';
        $progressBarColor = $this->hexToSwiftColor($config['progress_bar']['color'] ?? '#FFFFFF');
        $swiftProgressBarDefault = $progressBarEnabled ? 'true' : 'false';
        $progressBarDirection = ($config['progress_bar']['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';

        // ── Per-entry resolved properties ─────────────────────────────────────
        // Priority: dynamic (runtime schedule) → staticEntry (build-time schedule) → config default
        $s = $hasSchedule ? '?? (Self.staticEntry?["loop"] as? NSNumber)?.boolValue ' : '';
        $lines[] = '    private var resolvedLoop: Bool { (Self.dynamic?["loop"] as? NSNumber)?.boolValue '.$s.'?? '.$swiftLoop.' }';
        $s = $hasSchedule ? '?? (Self.staticEntry?["on_complete"] as? NSNumber)?.boolValue ' : '';
        $lines[] = '    private var resolvedOnComplete: Bool { (Self.dynamic?["on_complete"] as? NSNumber)?.boolValue '.$s.'?? '.$swiftOnComplete.' }';
        $s = $hasSchedule ? '?? (Self.staticEntry?["on_loop"] as? NSNumber)?.boolValue ' : '';
        $lines[] = '    private var resolvedOnLoop: Bool { (Self.dynamic?["on_loop"] as? NSNumber)?.boolValue '.$s.'?? '.$swiftOnLoop.' }';
        $s = $hasSchedule ? ' ?? Self.staticEntry?["transition_out"] as? String' : '';
        $lines[] = '    private var resolvedTransitionType: String { (Self.dynamic?["transition_out"] as? String'.$s.') ?? "'.addslashes($transitionType).'" }';

        $lines[] = '    private var resolvedTransitionDuration: Double {';
        $lines[] = '        if let n = Self.dynamic?["transition_duration"] as? NSNumber { return n.doubleValue / 1000.0 }';
        if ($hasSchedule) {
            $lines[] = '        if let n = Self.staticEntry?["transition_duration"] as? NSNumber { return n.doubleValue / 1000.0 }';
        }
        $lines[] = '        return '.$transitionDurationSecs;
        $lines[] = '    }';

        $s = $hasSchedule ? ' ?? Self.staticEntry?["transition_origin"] as? String' : '';
        $lines[] = '    private var resolvedTransitionOrigin: String { (Self.dynamic?["transition_origin"] as? String'.$s.') ?? "'.addslashes($transitionOrigin).'" }';

        $lines[] = '    private var resolvedDelayAfter: Double {';
        $lines[] = '        if let n = Self.dynamic?["delay_after"] as? NSNumber { return n.doubleValue / 1000.0 }';
        if ($hasSchedule) {
            $lines[] = '        if let n = Self.staticEntry?["delay_after"] as? NSNumber { return n.doubleValue / 1000.0 }';
        }
        $lines[] = '        return '.$delayAfter;
        $lines[] = '    }';

        $lines[] = '    private var resolvedFadeIn: Double {';
        $lines[] = '        if let n = Self.dynamic?["fade_in"] as? NSNumber { return n.doubleValue / 1000.0 }';
        if ($hasSchedule) {
            $lines[] = '        if let n = Self.staticEntry?["fade_in"] as? NSNumber { return n.doubleValue / 1000.0 }';
        }
        $lines[] = '        return '.$fadeIn;
        $lines[] = '    }';

        $lines[] = '    private var resolvedDelayBefore: Double {';
        $lines[] = '        if let n = Self.dynamic?["delay_before"] as? NSNumber { return n.doubleValue / 1000.0 }';
        if ($hasSchedule) {
            $lines[] = '        if let n = Self.staticEntry?["delay_before"] as? NSNumber { return n.doubleValue / 1000.0 }';
        }
        $lines[] = '        return '.$delayBefore;
        $lines[] = '    }';

        if ($isAnimation) {
            $s = $hasSchedule ? '?? (Self.staticEntry?["size"] as? NSNumber)?.doubleValue ' : '';
            $lines[] = '    private var resolvedSize: Double { (Self.dynamic?["size"] as? NSNumber)?.doubleValue '.$s.'?? '.$size.' }';
        }

        $s = $hasSchedule ? '?? (Self.staticEntry?["show_icon"] as? NSNumber)?.boolValue ' : '';
        $lines[] = '    private var resolvedShowIcon: Bool { (Self.dynamic?["show_icon"] as? NSNumber)?.boolValue '.$s.'?? '.$swiftShowIcon.' }';
        $s = $hasSchedule ? ' ?? Self.staticEntry?["icon_position"] as? String' : '';
        $lines[] = '    private var resolvedIconPosition: String { (Self.dynamic?["icon_position"] as? String'.$s.') ?? "'.addslashes($iconPosition).'" }';
        $s = $hasSchedule ? '?? (Self.staticEntry?["icon_size"] as? NSNumber)?.doubleValue ' : '';
        $lines[] = '    private var resolvedIconSize: Double { (Self.dynamic?["icon_size"] as? NSNumber)?.doubleValue '.$s.'?? '.$iconSize.' }';
        $s = $hasSchedule ? '?? (Self.staticEntry?["icon_radius"] as? NSNumber)?.doubleValue ' : '';
        $lines[] = '    private var resolvedIconRadius: Double { (Self.dynamic?["icon_radius"] as? NSNumber)?.doubleValue '.$s.'?? '.$iconRadius.' }';
        $s = $hasSchedule ? '?? (Self.staticEntry?["progress_bar"] as? NSNumber)?.boolValue ' : '';
        $lines[] = '    private var resolvedShowProgressBar: Bool { (Self.dynamic?["progress_bar"] as? NSNumber)?.boolValue '.$s.'?? '.$swiftProgressBarDefault.' }';
        $lines[] = '    private var resolvedProgressBarColor: Color {';
        $lines[] = '        if let hex = Self.dynamic?["progress_bar_color"] as? String { return Color(hexString: hex) }';
        if ($hasSchedule) {
            $lines[] = '        if let hex = Self.staticEntry?["progress_bar_color"] as? String { return Color(hexString: hex) }';
        }
        $lines[] = '        return '.$progressBarColor;
        $lines[] = '    }';
        $s = $hasSchedule ? ' ?? Self.staticEntry?["progress_bar_direction"] as? String' : '';
        $lines[] = '    private var resolvedProgressBarDirection: String { (Self.dynamic?["progress_bar_direction"] as? String'.$s.') ?? "'.$progressBarDirection.'" }';
        $lines[] = '    private var resolvedProgressBarAlignment: Alignment { resolvedProgressBarDirection == "rtl" ? .trailing : .leading }';
        $lines[] = '';

        // ── resolvedAlignmentValue ─────────────────────────────────────────────
        $positionExpr = $hasSchedule
            ? '(Self.dynamic?["position"] as? String) ?? (Self.staticEntry?["position"] as? String) ?? "'.addslashes($position).'"'
            : 'Self.dynamic?["position"] as? String ?? "'.addslashes($position).'"';
        $lines[] = '    private var resolvedAlignmentValue: Alignment {';
        $lines[] = '        switch '.$positionExpr.' {';
        $lines[] = '        case "top": return .top';
        $lines[] = '        case "bottom": return .bottom';
        $lines[] = '        default: return .center';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';

        // ── resolvedBackground — dynamic → staticEntry → nil ──────────────────
        if ($hasSchedule) {
            $lines[] = '    private var resolvedBackground: [String: Any]? {';
            $lines[] = '        (Self.dynamic?["background"] as? [String: Any])';
            $lines[] = '            ?? (Self.staticEntry?["background"] as? [String: Any])';
            $lines[] = '    }';
            $lines[] = '';
        }

        // ── currentAnimationName ──────────────────────────────────────────────
        $lines[] = '    private var currentAnimationName: String {';
        $lines[] = '        if let dynAnim = Self.dynamic?["animation"] as? String {';
        $lines[] = '            return (dynAnim as NSString).lastPathComponent.replacingOccurrences(of: ".lottie", with: "")';
        $lines[] = '        }';
        if ($hasSchedule) {
            $lines[] = '        if let statAnim = Self.staticEntry?["animation"] as? String { return statAnim }';
        }
        if ($usesColorScheme && $hasDarkAnim) {
            $lines[] = '        if colorScheme == .dark { return "'.addslashes($darkAnimName).'" }';
        }
        $lines[] = '        return "'.addslashes($activeAnimName).'"';
        $lines[] = '    }';
        $lines[] = '';

        // ── Background layer ──────────────────────────────────────────────────
        $bgRef = $hasSchedule ? 'resolvedBackground' : 'Self.dynamic?["background"] as? [String: Any]';
        $lines[] = '    @ViewBuilder';
        $lines[] = '    private var backgroundLayer: some View {';
        $lines[] = '        if let bg = '.$bgRef.' {';
        $lines[] = '            let type = bg["type"] as? String ?? "color"';
        $lines[] = '            if type == "gradient", let colors = bg["colors"] as? [String], colors.count >= 2 {';
        $lines[] = '                LinearGradient(';
        $lines[] = '                    colors: colors.map { Color(hexString: $0) },';
        $lines[] = '                    startPoint: .top, endPoint: .bottom';
        $lines[] = '                ).ignoresSafeArea()';
        $lines[] = '            } else if let hex = bg["color"] as? String {';
        $lines[] = '                Color(hexString: hex).ignoresSafeArea()';
        $lines[] = '            } else {';
        foreach ($this->iosBackgroundLines($activeBg) as $bgLine) {
            $lines[] = '                '.$bgLine;
        }
        $lines[] = '            }';
        $lines[] = '        } else';
        if ($hasDarkBg) {
            $lines[] = '        if colorScheme == .dark {';
            foreach ($this->iosBackgroundLines($config['theme']['dark']['background']) as $bgLine) {
                $lines[] = '            '.$bgLine;
            }
            $lines[] = '        } else {';
            foreach ($this->iosBackgroundLines($activeBg) as $bgLine) {
                $lines[] = '            '.$bgLine;
            }
            $lines[] = '        }';
        } else {
            $lines[] = '        {';
            foreach ($this->iosBackgroundLines($activeBg) as $bgLine) {
                $lines[] = '            '.$bgLine;
            }
            $lines[] = '        }';
        }
        $lines[] = '    }';
        $lines[] = '';

        // ── Icon view helper ──────────────────────────────────────────────────
        // Xcode compiles appiconset into individual PNGs (e.g. AppIcon60x60@2x.png).
        // UIImage(named: "AppIcon") doesn't find them, so we probe known filenames first.
        $lines[] = '    private var appIconImage: UIImage? {';
        $lines[] = '        let candidates = [';
        $lines[] = '            "AppIcon60x60@3x", "AppIcon60x60@2x",';
        $lines[] = '            "AppIcon76x76@2x", "AppIcon83.5x83.5@2x",';
        $lines[] = '            "AppIcon", "Icon",';
        $lines[] = '        ]';
        $lines[] = '        for name in candidates {';
        $lines[] = '            if let icon = UIImage(named: name) { return icon }';
        $lines[] = '        }';
        $lines[] = '        return nil';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    @ViewBuilder';
        $lines[] = '    private func iconView(geometry: GeometryProxy) -> some View {';
        $lines[] = '        let iconW = geometry.size.width * resolvedIconSize';
        $lines[] = '        let radius = iconW * resolvedIconRadius';
        $lines[] = '        Group {';
        $lines[] = '            if let appIcon = appIconImage {';
        $lines[] = '                Image(uiImage: appIcon.withRenderingMode(.alwaysOriginal))';
        $lines[] = '                    .resizable()';
        $lines[] = '                    .scaledToFit()';
        $lines[] = '            } else {';
        $lines[] = '                Image(systemName: "app.fill")';
        $lines[] = '                    .resizable()';
        $lines[] = '                    .scaledToFit()';
        $lines[] = '                    .foregroundColor(.white)';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '        .frame(width: iconW)';
        $lines[] = '        .clipShape(RoundedRectangle(cornerRadius: radius))';
        $lines[] = '    }';
        $lines[] = '';

        // ── Animation content helper ──────────────────────────────────────────
        if ($isAnimation) {
            $lines[] = '    @ViewBuilder';
            $lines[] = '    private func animationContent(geometry: GeometryProxy) -> some View {';
            $lines[] = '        LottieView {';
            $lines[] = '            if let dynPath = Self.dynamic?["animation"] as? String,';
            $lines[] = '               let support = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first {';
            $lines[] = '                let url = support.appendingPathComponent("storage/app/\(dynPath)")';
            $lines[] = '                return try await DotLottieFile.loadedFrom(url: url)';
            $lines[] = '            }';
            $lines[] = '            return try await DotLottieFile.named(currentAnimationName)';
            $lines[] = '        }';
            $lines[] = '        .playing(loopMode: resolvedLoop ? .loop : .playOnce)';
            $lines[] = '        .animationDidFinish { completed in';
            $lines[] = '            guard completed else { return }';
            $lines[] = '            if resolvedLoop {';
            $lines[] = '                guard !exitTriggered else { return }';
            $lines[] = '                loopCount += 1';
            $lines[] = '                if resolvedOnLoop {';
            $lines[] = '                    LaravelBridge.shared.send?(';
            $lines[] = '                        "S2BR\\\\MobileSplashscreen\\\\Events\\\\SplashscreenLoopCompleted",';
            $lines[] = '                        ["iteration": loopCount, "animationName": currentAnimationName]';
            $lines[] = '                    )';
            $lines[] = '                }';
            $lines[] = '            } else {';
            $lines[] = '                animationCompleted = true';
            $lines[] = '            }';
            $lines[] = '        }';
            $lines[] = '        .frame(';
            $lines[] = '            width: geometry.size.width * resolvedSize,';
            $lines[] = '            height: geometry.size.width * resolvedSize';
            $lines[] = '        )';
            $lines[] = '    }';
            $lines[] = '';
        }

        // ── circlePosition helper ─────────────────────────────────────────────
        $lines[] = '    private func circlePosition(in geometry: GeometryProxy) -> CGPoint {';
        $lines[] = '        switch resolvedTransitionOrigin {';
        $lines[] = '        case "top": return CGPoint(x: geometry.size.width / 2, y: 0)';
        $lines[] = '        case "bottom": return CGPoint(x: geometry.size.width / 2, y: geometry.size.height)';
        $lines[] = '        case "top_left": return CGPoint(x: 0, y: 0)';
        $lines[] = '        case "top_right": return CGPoint(x: geometry.size.width, y: 0)';
        $lines[] = '        case "bottom_left": return CGPoint(x: 0, y: geometry.size.height)';
        $lines[] = '        case "bottom_right": return CGPoint(x: geometry.size.width, y: geometry.size.height)';
        $lines[] = '        case "center_left": return CGPoint(x: 0, y: geometry.size.height / 2)';
        $lines[] = '        case "center_right": return CGPoint(x: geometry.size.width, y: geometry.size.height / 2)';
        $lines[] = '        default: return CGPoint(x: geometry.size.width / 2, y: geometry.size.height / 2)';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';

        // ── Body ─────────────────────────────────────────────────────────────
        $lines[] = '    var body: some View {';
        $lines[] = '        GeometryReader { geometry in';
        $lines[] = '            ZStack(alignment: resolvedAlignmentValue) {';
        $lines[] = '                backgroundLayer';
        $lines[] = '';

        if ($isAnimation) {
            $lines[] = '                if resolvedShowIcon {';
            $lines[] = '                    VStack(spacing: 24) {';
            $lines[] = '                        if resolvedIconPosition == "top" {';
            $lines[] = '                            iconView(geometry: geometry)';
            $lines[] = '                        }';
            $lines[] = '                        animationContent(geometry: geometry)';
            $lines[] = '                        if resolvedIconPosition != "top" {';
            $lines[] = '                            iconView(geometry: geometry)';
            $lines[] = '                        }';
            $lines[] = '                    }';
            $lines[] = '                } else {';
            $lines[] = '                    animationContent(geometry: geometry)';
            $lines[] = '                }';
        } else {
            $textLines = $this->iosTextLines($config['text']);
            $lines[] = '                if resolvedShowIcon {';
            $lines[] = '                    VStack(spacing: 24) {';
            $lines[] = '                        if resolvedIconPosition == "top" {';
            $lines[] = '                            iconView(geometry: geometry)';
            $lines[] = '                        }';
            foreach ($textLines as $line) {
                $lines[] = '                        '.$line;
            }
            $lines[] = '                        if resolvedIconPosition != "top" {';
            $lines[] = '                            iconView(geometry: geometry)';
            $lines[] = '                        }';
            $lines[] = '                    }';
            $lines[] = '                } else {';
            foreach ($textLines as $line) {
                $lines[] = '                    '.$line;
            }
            $lines[] = '                }';
        }

        $lines[] = '';

        $lines[] = '                if showProgressBar {';
        $lines[] = '                    VStack {';
        $lines[] = '                        Spacer()';
        $lines[] = '                        ZStack(alignment: resolvedProgressBarAlignment) {';
        $lines[] = '                            Capsule()';
        $lines[] = '                                .fill(resolvedProgressBarColor.opacity(0.15))';
        $lines[] = '                                .frame(width: geometry.size.width * 0.5, height: 3)';
        $lines[] = '                            Capsule()';
        $lines[] = '                                .fill(resolvedProgressBarColor.opacity(0.6))';
        $lines[] = '                                .frame(width: max(0, geometry.size.width * 0.5 * loadProgress), height: 3)';
        $lines[] = '                        }';
        $lines[] = '                        .padding(.bottom, 48)';
        $lines[] = '                    }';
        $lines[] = '                    .frame(maxWidth: .infinity, maxHeight: .infinity)';
        $lines[] = '                    .transition(.opacity)';
        $lines[] = '                }';
        $lines[] = '';

        $lines[] = '                Circle()';
        $lines[] = '                    .fill(Color.white)';
        $lines[] = '                    .blendMode(.destinationOut)';
        $lines[] = '                    .frame(width: 2, height: 2)';
        $lines[] = '                    .scaleEffect(circleScale)';
        $lines[] = '                    .position(circlePosition(in: geometry))';

        $lines[] = '            }';
        $lines[] = '            .compositingGroup()';
        $lines[] = '            .frame(maxWidth: .infinity, maxHeight: .infinity)';
        $lines[] = '        }';
        $lines[] = '        .ignoresSafeArea()';
        $lines[] = '        .opacity(contentOpacity * exitOpacity)';
        $lines[] = '        .scaleEffect(exitScale)';
        $lines[] = '        .offset(y: exitOffset)';

        $lines[] = '        .onAppear {';
        $lines[] = '            Task {';
        $lines[] = '                let delayNs = UInt64(resolvedDelayBefore * 1_000_000_000)';
        $lines[] = '                if delayNs > 0 { try? await Task.sleep(nanoseconds: delayNs) }';
        $lines[] = '                withAnimation(.easeOut(duration: resolvedFadeIn)) { contentOpacity = 1 }';
        if (! $isAnimation) {
            $lines[] = '                let fadeNs = UInt64(resolvedFadeIn * 1_000_000_000)';
            $lines[] = '                if fadeNs > 0 { try? await Task.sleep(nanoseconds: fadeNs) }';
            $lines[] = '                guard !webViewReady, resolvedShowProgressBar else { return }';
            $lines[] = '                withAnimation(.easeIn(duration: 0.4)) { showProgressBar = true }';
            $lines[] = '                while !webViewReady {';
            $lines[] = '                    let remaining = 0.88 - loadProgress';
            $lines[] = '                    if remaining > 0.001 {';
            $lines[] = '                        withAnimation(.easeOut(duration: 0.4)) { loadProgress += remaining * 0.15 }';
            $lines[] = '                    }';
            $lines[] = '                    try? await Task.sleep(nanoseconds: 400_000_000)';
            $lines[] = '                }';
            $lines[] = '                withAnimation(.easeOut(duration: 0.3)) { loadProgress = 1.0 }';
        }
        $lines[] = '            }';
        $lines[] = '        }';

        $lines[] = '        .onChange(of: appState.isInitialized) { ready in';
        $lines[] = '            guard ready else { return }';
        $lines[] = '            webViewReady = true';
        $lines[] = '        }';
        $lines[] = '        .onChange(of: webViewReady) { ready in';
        $lines[] = '            guard ready else { return }';
        if ($isAnimation) {
            $lines[] = '            if resolvedLoop || animationCompleted { triggerExit() }';
        } else {
            $lines[] = '            triggerExit()';
        }
        $lines[] = '        }';
        if ($isAnimation) {
            $lines[] = '        .onChange(of: animationCompleted) { done in';
            $lines[] = '            guard done, webViewReady, !resolvedLoop else { return }';
            $lines[] = '            triggerExit()';
            $lines[] = '        }';
            $lines[] = '        .onChange(of: animationCompleted) { done in';
            $lines[] = '            guard done, !resolvedLoop, !webViewReady, resolvedShowProgressBar else { return }';
            $lines[] = '            withAnimation(.easeIn(duration: 0.4)) { showProgressBar = true }';
            $lines[] = '            Task {';
            $lines[] = '                while !webViewReady {';
            $lines[] = '                    let remaining = 0.88 - loadProgress';
            $lines[] = '                    if remaining > 0.001 {';
            $lines[] = '                        withAnimation(.easeOut(duration: 0.4)) { loadProgress += remaining * 0.15 }';
            $lines[] = '                    }';
            $lines[] = '                    try? await Task.sleep(nanoseconds: 400_000_000)';
            $lines[] = '                }';
            $lines[] = '                withAnimation(.easeOut(duration: 0.3)) { loadProgress = 1.0 }';
            $lines[] = '            }';
            $lines[] = '        }';
        }
        $lines[] = '    }';

        // ── triggerExit ───────────────────────────────────────────────────────
        $lines[] = '';
        $lines[] = '    private func triggerExit() {';
        $lines[] = '        guard !exitTriggered else { return }';
        $lines[] = '        exitTriggered = true';
        $lines[] = '        let duration = resolvedTransitionDuration';
        $lines[] = '        switch resolvedTransitionType {';
        $lines[] = '        case "fade":';
        $lines[] = '            withAnimation(.easeOut(duration: duration)) { exitOpacity = 0 }';
        $lines[] = '        case "scale_up":';
        $lines[] = '            withAnimation(.easeOut(duration: duration)) { exitOpacity = 0; exitScale = 1.5 }';
        $lines[] = '        case "scale_down":';
        $lines[] = '            withAnimation(.easeOut(duration: duration)) { exitOpacity = 0; exitScale = 0.7 }';
        $lines[] = '        case "slide_up":';
        $lines[] = '            withAnimation(.easeIn(duration: duration)) { exitOffset = -1000 }';
        $lines[] = '        case "slide_down":';
        $lines[] = '            withAnimation(.easeIn(duration: duration)) { exitOffset = 1000 }';
        $lines[] = '        case "circle_expand":';
        $lines[] = '            withAnimation(.easeIn(duration: duration)) { circleScale = 1000 }';
        $lines[] = '        default: break';
        $lines[] = '        }';
        $lines[] = '        let totalSleepNs = UInt64((duration + resolvedDelayAfter) * 1_000_000_000)';
        $lines[] = '        Task {';
        $lines[] = '            if totalSleepNs > 0 { try? await Task.sleep(nanoseconds: totalSleepNs) }';
        $lines[] = '            if resolvedOnComplete {';
        $lines[] = '                LaravelBridge.shared.send?(';
        $lines[] = '                    "S2BR\\\\MobileSplashscreen\\\\Events\\\\SplashscreenCompleted",';
        $lines[] = '                    ["animationName": currentAnimationName, "duration": 0.0]';
        $lines[] = '                )';
        $lines[] = '            }';
        $lines[] = '            await MainActor.run { AppState.shared.isSplashDismissed = true }';
        $lines[] = '        }';
        $lines[] = '    }';

        $lines[] = '}';
        $lines[] = '';
        $lines[] = '#Preview {';
        $lines[] = '    SplashView()';
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * Normalise static schedule entries for embedding as JSON in native code.
     * Backgrounds are flattened to match the dynamic schedule format so the same
     * native resolution code handles both sources.
     */
    protected function iosEmbedScheduleEntries(array $schedule): array
    {
        $entries = [];
        $passThrough = [
            'loop', 'size', 'position', 'transition_out', 'transition_duration',
            'transition_origin', 'delay_before', 'fade_in', 'delay_after',
            'on_complete', 'on_loop', 'show_icon', 'icon_size', 'icon_position', 'icon_radius',
            'progress_bar', 'progress_bar_color', 'progress_bar_direction',
        ];

        foreach ($schedule as $entry) {
            $row = [
                'from' => $entry['from'] ?? '',
                'to' => $entry['to'] ?? '',
            ];

            if (isset($entry['animation'])) {
                // iOS uses animation name without extension (DotLottieFile.named)
                $row['animation'] = pathinfo($entry['animation'], PATHINFO_FILENAME);
            }

            if (isset($entry['background'])) {
                $row['background'] = $this->normalizeScheduleBackground($entry['background']);
            }

            foreach ($passThrough as $field) {
                if (isset($entry[$field])) {
                    $row[$field] = $entry[$field];
                }
            }

            $entries[] = $row;
        }

        return $entries;
    }

    /**
     * Flatten a config-format background to the dynamic schedule format so both
     * use the same structure in native code.
     * Config format:  { type: gradient, gradient: { colors: [...], direction: ... } }
     * Flat format:    { type: gradient, colors: [...] }
     */
    protected function normalizeScheduleBackground(array $bg): array
    {
        if (($bg['type'] ?? 'color') === 'gradient') {
            return [
                'type' => 'gradient',
                'colors' => $bg['gradient']['colors'] ?? [],
            ];
        }

        return [
            'type' => 'color',
            'color' => $bg['color'] ?? '#FFFFFF',
        ];
    }

    /**
     * Swift lines that resolve today's matching entry from a `schedule` array
     * already in scope, returning the entry (or nil).
     *
     * Full dates (YYYY-MM-DD) match only that calendar year; partial dates
     * (MM-DD) match the same month/day every year. A full-date match always takes
     * precedence over a partial-date match; within a tier the first entry wins.
     */
    protected function iosScheduleMatchLines(): array
    {
        return [
            '        let formatter = DateFormatter()',
            '        formatter.dateFormat = "yyyy-MM-dd"',
            '        let today = formatter.string(from: Date())',
            '        let todayMD = String(today.suffix(5))',
            '        var fullMatch: [String: Any]? = nil',
            '        var partialMatch: [String: Any]? = nil',
            '        for entry in schedule {',
            '            guard let from = entry["from"] as? String, let to = entry["to"] as? String, !from.isEmpty, !to.isEmpty else { continue }',
            '            let isFull = from.count == 10 && to.count == 10',
            '            let t = isFull ? today : todayMD',
            '            let inRange = from <= to ? (t >= from && t <= to) : (t >= from || t <= to)',
            '            if inRange {',
            '                if isFull { fullMatch = entry; break }',
            '                if partialMatch == nil { partialMatch = entry }',
            '            }',
            '        }',
            '        return fullMatch ?? partialMatch',
        ];
    }

    protected function iosParseSchedule(array $config): array
    {
        $schedulePath = $config['schedule_path'] ?? null;
        if (! $schedulePath) {
            return [];
        }

        $fullPath = base_path($schedulePath);
        if (! file_exists($fullPath)) {
            return [];
        }

        $data = json_decode(file_get_contents($fullPath), true);

        return $data['schedule'] ?? [];
    }

    protected function iosBackgroundLines(array $bg): array
    {
        $type = $bg['type'] ?? 'color';

        if ($type === 'gradient') {
            $colors = $bg['gradient']['colors'] ?? ['#FFFFFF', '#000000'];
            $direction = $bg['gradient']['direction'] ?? 'vertical';
            $colorList = implode(', ', array_map(fn ($h) => $this->hexToSwiftColor($h), $colors));

            [$start, $end] = match ($direction) {
                'horizontal' => ['.leading', '.trailing'],
                'diagonal' => ['.topLeading', '.bottomTrailing'],
                default => ['.top', '.bottom'],
            };

            return [
                'LinearGradient(',
                '    colors: ['.$colorList.'],',
                '    startPoint: '.$start.',',
                '    endPoint: '.$end,
                ')',
                '.ignoresSafeArea()',
            ];
        }

        return [
            $this->hexToSwiftColor($bg['color'] ?? '#FFFFFF'),
            '.ignoresSafeArea()',
        ];
    }

    protected function iosTextLines(array $text): array
    {
        $message = str_replace('"', '\\"', $text['message'] ?? '');
        $size = (int) ($text['size'] ?? 32);
        $weight = $this->iosTextWeight($text['weight'] ?? 'bold');
        $color = $this->hexToSwiftColor($text['color'] ?? '#FFFFFF');

        return [
            'Text("'.$message.'")',
            '    .font(.system(size: '.$size.', weight: '.$weight.'))',
            '    .foregroundColor('.$color.')',
            '    .multilineTextAlignment(.center)',
            '    .padding(.horizontal, 32)',
        ];
    }

    protected function iosTextWeight(string $weight): string
    {
        return match ($weight) {
            'thin' => '.thin',
            'light' => '.light',
            'regular' => '.regular',
            'medium' => '.medium',
            'semibold' => '.semibold',
            'heavy' => '.heavy',
            'black' => '.black',
            default => '.bold',
        };
    }

    protected function hexToSwiftColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        return sprintf('Color(red: %.3f, green: %.3f, blue: %.3f)', $r, $g, $b);
    }

    protected function hexToStoryboardColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        return sprintf(
            'red="%.6f" green="%.6f" blue="%.6f" alpha="1" colorSpace="custom" customColorSpace="sRGB"',
            $r, $g, $b
        );
    }
}
