<?php

namespace S2BR\MobileSplashscreen\Concerns;

trait GeneratesAndroidCode
{
    protected function buildAndroidSplashScreen(array $config, string $animationFilename): string
    {
        $isAnimation = $config['content'] === 'animation';
        $isLoop = $isAnimation && ($config['animation']['loop'] ?? true);
        $showIcon = $config['icon']['show'] ?? false;
        $onLoop = $isLoop && ($config['events']['on_loop'] ?? false);
        $onComplete = ! $isLoop && ($config['events']['on_complete'] ?? true);
        $progressBarEnabled = $config['progress_bar']['enabled'] ?? false;
        $progressBarColor = $this->hexToComposeColor($config['progress_bar']['color'] ?? '#FFFFFF');
        $kotlinProgressBarDefault = $progressBarEnabled ? 'true' : 'false';
        $progressBarDirection = ($config['progress_bar']['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
        $isGradient = ($config['background']['type'] ?? 'color') === 'gradient';
        $isDiagonal = $isGradient && ($config['background']['gradient']['direction'] ?? 'vertical') === 'diagonal';
        $fadeIn = (int) ($config['timing']['fade_in'] ?? 600);
        $delayBefore = (int) ($config['timing']['delay_before'] ?? 0);
        $delayAfter = (int) ($config['timing']['delay_after'] ?? 0);

        $size = number_format(max(0.1, min(1.0, $config['animation']['size'] ?? 0.8)), 2, '.', '');
        $position = $config['animation']['position'] ?? 'center';
        $iconSize = number_format(max(0.1, min(0.5, $config['icon']['size'] ?? 0.2)), 2, '.', '');
        $iconRadius = max(0, min(50, (int) round(($config['icon']['corner_radius'] ?? 0.22) * 100)));
        $iconPosition = $config['icon']['position'] ?? 'bottom';

        $themeMode = $config['theme']['mode'] ?? 'auto';
        $usesColorScheme = $themeMode === 'auto';
        $hasDarkBg = $usesColorScheme && ($config['theme']['dark']['background']['type'] ?? null) !== null;
        $hasDarkAnim = $usesColorScheme && ($config['theme']['dark']['animation_path'] ?? null) !== null;
        $darkAnimFilename = $hasDarkAnim
            ? pathinfo($config['theme']['dark']['animation_path'], PATHINFO_FILENAME).'.lottie'
            : null;

        $schedule = $this->androidParseSchedule($config);
        $hasSchedule = ! empty($schedule);
        $hasScheduleBg = $hasSchedule && ! empty(array_filter($schedule, fn ($e) => isset($e['background'])));

        $transition = $config['transition_out'] ?? [];
        $transitionType = $transition['type'] ?? 'none';
        $transitionDurationMs = (int) ($transition['duration'] ?? 400);
        $transitionOrigin = $transition['origin'] ?? 'center';

        // Resolve static config for fallback values
        if ($themeMode === 'dark') {
            $staticAnimFilename = ($config['theme']['dark']['animation_path'] ?? null)
                ? pathinfo($config['theme']['dark']['animation_path'], PATHINFO_FILENAME).'.lottie'
                : $animationFilename;
            $staticBg = $config['theme']['dark']['background']['type'] !== null
                ? $config['theme']['dark']['background']
                : $config['background'];
        } else {
            $staticAnimFilename = $animationFilename;
            $staticBg = $config['background'];
        }

        $usesDynamicBg = $hasDarkBg || $hasScheduleBg;

        $kotlinLoop = $isLoop ? 'true' : 'false';
        $kotlinOnComplete = $onComplete ? 'true' : 'false';
        $kotlinOnLoop = $onLoop ? 'true' : 'false';
        $kotlinShowIcon = $showIcon ? 'true' : 'false';

        $lines = [];
        $lines[] = '    @Composable';
        $lines[] = '    private fun SplashScreen() {';

        // ── Dynamic schedule loader (runtime, from sync command) ─────────────────
        $lines[] = '        val dynamicConfig: Map<String, Any>? = remember {';
        $lines[] = '            try {';
        $lines[] = '                val file = java.io.File(filesDir, "storage/app/splashscreen/schedule-local.json")';
        $lines[] = '                if (!file.exists()) return@remember null';
        $lines[] = '                val root = org.json.JSONObject(file.readText())';
        $lines[] = '                val schedule = root.optJSONArray("schedule") ?: return@remember null';
        $lines[] = '                val fmt = java.text.SimpleDateFormat("yyyy-MM-dd", java.util.Locale.US)';
        $lines[] = '                val today = fmt.format(java.util.Date())';
        $lines[] = '                for (i in 0 until schedule.length()) {';
        $lines[] = '                    val entry = schedule.getJSONObject(i)';
        $lines[] = '                    val from = entry.optString("from", "")';
        $lines[] = '                    val to = entry.optString("to", "")';
        $lines[] = '                    if (from.isEmpty() || to.isEmpty()) continue';
        $lines[] = '                    val inRange = if (from <= to) today >= from && today <= to else today >= from || today <= to';
        $lines[] = '                    if (inRange) return@remember buildMap { entry.keys().forEach { k -> put(k, entry.get(k)) } }';
        $lines[] = '                }';
        $lines[] = '                null';
        $lines[] = '            } catch (e: Exception) { null }';
        $lines[] = '        }';
        $lines[] = '';

        // ── Static schedule loader (build-time, embedded JSON) ─────────────────
        // Supports all per-entry overrides — same priority as dynamic but lower.
        if ($hasSchedule) {
            $embeddedEntries = $this->androidEmbedScheduleEntries($schedule);
            $json = json_encode($embeddedEntries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $lines[] = '        val staticEntry: Map<String, Any>? = remember {';
            $lines[] = '            try {';
            $lines[] = '                val json = """'.$json.'"""';
            $lines[] = '                val arr = org.json.JSONArray(json)';
            $lines[] = '                val fmt = java.text.SimpleDateFormat("yyyy-MM-dd", java.util.Locale.US)';
            $lines[] = '                val today = fmt.format(java.util.Date())';
            $lines[] = '                for (i in 0 until arr.length()) {';
            $lines[] = '                    val entry = arr.getJSONObject(i)';
            $lines[] = '                    val from = entry.optString("from", "")';
            $lines[] = '                    val to = entry.optString("to", "")';
            $lines[] = '                    if (from.isEmpty() || to.isEmpty()) continue';
            $lines[] = '                    val inRange = if (from <= to) today >= from && today <= to else today >= from || today <= to';
            $lines[] = '                    if (inRange) return@remember buildMap { entry.keys().forEach { k -> put(k, entry.get(k)) } }';
            $lines[] = '                }';
            $lines[] = '                null';
            $lines[] = '            } catch (e: Exception) { null }';
            $lines[] = '        }';
            $lines[] = '';
        }

        // ── Resolved properties: dynamic → staticEntry → config default ──────────
        $se = $hasSchedule ? ' ?: (staticEntry?.get("loop") as? Boolean)' : '';
        $lines[] = '        val resolvedLoop = (dynamicConfig?.get("loop") as? Boolean)'.$se.' ?: '.$kotlinLoop;
        $se = $hasSchedule ? ' ?: (staticEntry?.get("on_complete") as? Boolean)' : '';
        $lines[] = '        val resolvedOnComplete = (dynamicConfig?.get("on_complete") as? Boolean)'.$se.' ?: '.$kotlinOnComplete;
        $se = $hasSchedule ? ' ?: (staticEntry?.get("on_loop") as? Boolean)' : '';
        $lines[] = '        val resolvedOnLoop = (dynamicConfig?.get("on_loop") as? Boolean)'.$se.' ?: '.$kotlinOnLoop;
        $se = $hasSchedule ? ' ?: (staticEntry?.get("transition_out") as? String)' : '';
        $lines[] = '        val resolvedTransitionType = (dynamicConfig?.get("transition_out") as? String)'.$se.' ?: "'.addslashes($transitionType).'"';
        $se = $hasSchedule ? ' ?: (staticEntry?.get("transition_duration") as? Number)?.toInt()' : '';
        $lines[] = '        val resolvedTransitionDurationMs = (dynamicConfig?.get("transition_duration") as? Number)?.toInt()'.$se.' ?: '.$transitionDurationMs;
        $se = $hasSchedule ? ' ?: (staticEntry?.get("transition_origin") as? String)' : '';
        $lines[] = '        val resolvedTransitionOrigin = (dynamicConfig?.get("transition_origin") as? String)'.$se.' ?: "'.addslashes($transitionOrigin).'"';
        $se = $hasSchedule ? ' ?: (staticEntry?.get("delay_after") as? Number)?.toLong()' : '';
        $lines[] = '        val resolvedDelayAfterMs = (dynamicConfig?.get("delay_after") as? Number)?.toLong()'.$se.' ?: '.$delayAfter.'L';
        $se = $hasSchedule ? ' ?: (staticEntry?.get("fade_in") as? Number)?.toInt()' : '';
        $lines[] = '        val resolvedFadeInMs = (dynamicConfig?.get("fade_in") as? Number)?.toInt()'.$se.' ?: '.$fadeIn;
        $se = $hasSchedule ? ' ?: (staticEntry?.get("delay_before") as? Number)?.toLong()' : '';
        $lines[] = '        val resolvedDelayBeforeMs = (dynamicConfig?.get("delay_before") as? Number)?.toLong()'.$se.' ?: '.$delayBefore.'L';
        if ($isAnimation) {
            $se = $hasSchedule ? ' ?: (staticEntry?.get("size") as? Number)?.toFloat()' : '';
            $lines[] = '        val resolvedSize = (dynamicConfig?.get("size") as? Number)?.toFloat()'.$se.' ?: '.$size.'f';
        }
        $se = $hasSchedule ? ' ?: (staticEntry?.get("show_icon") as? Boolean)' : '';
        $lines[] = '        val resolvedShowIcon = (dynamicConfig?.get("show_icon") as? Boolean)'.$se.' ?: '.$kotlinShowIcon;
        $se = $hasSchedule ? ' ?: (staticEntry?.get("icon_position") as? String)' : '';
        $lines[] = '        val resolvedIconPosition = (dynamicConfig?.get("icon_position") as? String)'.$se.' ?: "'.addslashes($iconPosition).'"';
        $se = $hasSchedule ? ' ?: (staticEntry?.get("icon_size") as? Number)?.toFloat()' : '';
        $lines[] = '        val resolvedIconSize = (dynamicConfig?.get("icon_size") as? Number)?.toFloat()'.$se.' ?: '.$iconSize.'f';
        $se = $hasSchedule ? ' ?: (staticEntry?.get("icon_radius") as? Number)?.toInt()' : '';
        $lines[] = '        val resolvedIconRadius = (dynamicConfig?.get("icon_radius") as? Number)?.toInt()'.$se.' ?: '.$iconRadius;
        $se = $hasSchedule ? ' ?: (staticEntry?.get("progress_bar") as? Boolean)' : '';
        $lines[] = '        val resolvedShowProgressBar = (dynamicConfig?.get("progress_bar") as? Boolean)'.$se.' ?: '.$kotlinProgressBarDefault;
        $lines[] = '        val resolvedProgressBarColor = run {';
        $lines[] = '            val hex = (dynamicConfig?.get("progress_bar_color") as? String)'.($hasSchedule ? ' ?: (staticEntry?.get("progress_bar_color") as? String)' : '');
        $lines[] = '            if (hex != null) Color(android.graphics.Color.parseColor(hex)) else '.$progressBarColor;
        $lines[] = '        }';
        $se = $hasSchedule ? ' ?: (staticEntry?.get("progress_bar_direction") as? String)' : '';
        $lines[] = '        val resolvedProgressBarDirection = (dynamicConfig?.get("progress_bar_direction") as? String)'.$se.' ?: "'.$progressBarDirection.'"';
        $lines[] = '        val resolvedProgressBarAlignment = if (resolvedProgressBarDirection == "rtl") Alignment.CenterEnd else Alignment.CenterStart';
        $posExpr = $hasSchedule
            ? '(dynamicConfig?.get("position") as? String) ?: (staticEntry?.get("position") as? String) ?: "'.addslashes($position).'"'
            : '(dynamicConfig?.get("position") as? String) ?: "'.addslashes($position).'"';
        $lines[] = '        val resolvedAlignment = when ('.$posExpr.') {';
        $lines[] = '            "top" -> Alignment.TopCenter';
        $lines[] = '            "bottom" -> Alignment.BottomCenter';
        $lines[] = '            else -> Alignment.Center';
        $lines[] = '        }';
        $lines[] = '';

        // ── Fade-in alpha ───────────────────────────────────────────────────────
        $lines[] = '        var contentAlpha by remember { mutableStateOf(0f) }';
        $lines[] = '        val alpha by animateFloatAsState(';
        $lines[] = '            targetValue = contentAlpha,';
        $lines[] = '            animationSpec = tween(durationMillis = resolvedFadeInMs, easing = FastOutSlowInEasing),';
        $lines[] = '            label = "splashFadeIn"';
        $lines[] = '        )';
        $lines[] = '';

        if ($usesColorScheme) {
            $lines[] = '        val isDark = isSystemInDarkTheme()';
        }

        // ── Resolved animation filename ─────────────────────────────────────────
        $lines[] = '        val resolvedAnimFilename = run {';
        $lines[] = '            val dynAnim = dynamicConfig?.get("animation") as? String';
        $lines[] = '            if (dynAnim != null) return@run java.io.File(dynAnim).name';
        if ($hasSchedule) {
            $lines[] = '            val statAnim = staticEntry?.get("animation") as? String';
            $lines[] = '            if (statAnim != null) return@run statAnim';
        }
        if ($usesColorScheme && $hasDarkAnim) {
            $lines[] = '            if (isDark) return@run "'.addslashes($darkAnimFilename).'"';
        }
        $lines[] = '            "'.addslashes($staticAnimFilename).'"';
        $lines[] = '        }';
        $lines[] = '';

        // ── Lottie composition ──────────────────────────────────────────────────
        if ($isAnimation) {
            $lines[] = '        val composition by rememberLottieComposition(';
            $lines[] = '            if (dynamicConfig?.get("animation") != null) {';
            $lines[] = '                val path = java.io.File(filesDir, "storage/app/splashscreen/animations/$resolvedAnimFilename").absolutePath';
            $lines[] = '                LottieCompositionSpec.File(path)';
            $lines[] = '            } else {';
            $lines[] = '                LottieCompositionSpec.Asset("animations/$resolvedAnimFilename")';
            $lines[] = '            }';
            $lines[] = '        )';
            $lines[] = '        val animatable = rememberLottieAnimatable()';
            $lines[] = '        var loopCount by remember { mutableStateOf(0) }';
            $lines[] = '';
        }

        // ── Always emit all transition state variables ───────────────────────────
        $lines[] = '        var isExiting by remember { mutableStateOf(false) }';
        $lines[] = '        val exitAlpha by animateFloatAsState(';
        $lines[] = '            targetValue = if (isExiting && resolvedTransitionType in setOf("fade", "scale_up", "scale_down")) 0f else 1f,';
        $lines[] = '            animationSpec = tween(durationMillis = resolvedTransitionDurationMs),';
        $lines[] = '            label = "exitAlpha"';
        $lines[] = '        )';
        $lines[] = '        val exitScale by animateFloatAsState(';
        $lines[] = '            targetValue = when {';
        $lines[] = '                isExiting && resolvedTransitionType == "scale_up" -> 1.5f';
        $lines[] = '                isExiting && resolvedTransitionType == "scale_down" -> 0.7f';
        $lines[] = '                else -> 1f';
        $lines[] = '            },';
        $lines[] = '            animationSpec = tween(durationMillis = resolvedTransitionDurationMs),';
        $lines[] = '            label = "exitScale"';
        $lines[] = '        )';
        $lines[] = '        val exitOffsetDp by animateDpAsState(';
        $lines[] = '            targetValue = when {';
        $lines[] = '                isExiting && resolvedTransitionType == "slide_up" -> (-1000).dp';
        $lines[] = '                isExiting && resolvedTransitionType == "slide_down" -> 1000.dp';
        $lines[] = '                else -> 0.dp';
        $lines[] = '            },';
        $lines[] = '            animationSpec = tween(durationMillis = resolvedTransitionDurationMs),';
        $lines[] = '            label = "exitOffset"';
        $lines[] = '        )';
        $lines[] = '        val circleProgress by animateFloatAsState(';
        $lines[] = '            targetValue = if (isExiting && resolvedTransitionType == "circle_expand") 1f else 0f,';
        $lines[] = '            animationSpec = tween(durationMillis = resolvedTransitionDurationMs, easing = FastOutSlowInEasing),';
        $lines[] = '            label = "circleExpand"';
        $lines[] = '        )';
        $lines[] = '';
        $lines[] = '        var targetProgress by remember { mutableStateOf(0f) }';
        $lines[] = '        val loadProgress by animateFloatAsState(';
        $lines[] = '            targetValue = targetProgress,';
        $lines[] = '            animationSpec = tween(durationMillis = 400),';
        $lines[] = '            label = "loadProgress"';
        $lines[] = '        )';
        $lines[] = '        var showProgressBar by remember { mutableStateOf(false) }';
        $lines[] = '';

        // ── Fade-in ─────────────────────────────────────────────────────────────
        $lines[] = '        LaunchedEffect(Unit) {';
        $lines[] = '            if (resolvedDelayBeforeMs > 0L) delay(resolvedDelayBeforeMs)';
        $lines[] = '            contentAlpha = 1f';
        $lines[] = '        }';

        // ── Text mode: progress bar (starts after fade-in, while web view loads) ──
        if (! $isAnimation) {
            $lines[] = '';
            $lines[] = '        LaunchedEffect(Unit) {';
            $lines[] = '            if (!resolvedShowProgressBar) return@LaunchedEffect';
            $lines[] = '            delay(resolvedFadeInMs.toLong())';
            $lines[] = '            if (webViewReadyForSplash) return@LaunchedEffect';
            $lines[] = '            showProgressBar = true';
            $lines[] = '            while (!webViewReadyForSplash) {';
            $lines[] = '                val remaining = 0.88f - targetProgress';
            $lines[] = '                if (remaining > 0.001f) targetProgress += remaining * 0.15f';
            $lines[] = '                delay(400)';
            $lines[] = '            }';
            $lines[] = '            targetProgress = 1.0f';
            $lines[] = '        }';
        }

        // ── Animation coroutine ─────────────────────────────────────────────────
        if ($isAnimation) {
            $lines[] = '';
            $lines[] = '        LaunchedEffect(composition) {';
            $lines[] = '            composition ?: return@LaunchedEffect';
            $lines[] = '            if (resolvedLoop) {';
            $lines[] = '                while (!webViewReadyForSplash) {';
            $lines[] = '                    animatable.animate(composition = composition, iterations = 1)';
            $lines[] = '                    loopCount++';
            $lines[] = '                }';
            $lines[] = '            } else {';
            $lines[] = '                animatable.animate(composition = composition, iterations = 1)';
            $lines[] = '                loopCount++';
            $lines[] = '            }';
            $lines[] = '        }';

            // ── Loop event dispatch ─────────────────────────────────────────────
            $lines[] = '';
            $lines[] = '        LaunchedEffect(loopCount) {';
            $lines[] = '            if (loopCount <= 0 || webViewReadyForSplash) return@LaunchedEffect';
            $lines[] = '            if (resolvedLoop && resolvedOnLoop) {';
            $lines[] = '                Handler(Looper.getMainLooper()).post {';
            $lines[] = '                    val payload = JSONObject().apply {';
            $lines[] = '                        put("iteration", loopCount)';
            $lines[] = '                        put("animationName", resolvedAnimFilename.removeSuffix(".lottie"))';
            $lines[] = '                    }';
            $lines[] = '                    NativeActionCoordinator.dispatchEvent(';
            $lines[] = '                        this@MainActivity,';
            $lines[] = '                        "S2BR\\\\\\\\MobileSplashscreen\\\\\\\\Events\\\\\\\\SplashscreenLoopCompleted",';
            $lines[] = '                        payload.toString()';
            $lines[] = '                    )';
            $lines[] = '                }';
            $lines[] = '            }';
            $lines[] = '        }';
        }

        // ── Exit coordination ───────────────────────────────────────────────────
        $lines[] = '';
        $exitKey = $isAnimation ? 'webViewReadyForSplash, loopCount' : 'webViewReadyForSplash';
        $lines[] = '        LaunchedEffect('.$exitKey.') {';
        $lines[] = '            if (!webViewReadyForSplash) return@LaunchedEffect';
        if ($isAnimation) {
            $lines[] = '            if (!resolvedLoop && loopCount == 0) return@LaunchedEffect';
        }
        $lines[] = '            isExiting = true';
        $lines[] = '            delay(resolvedTransitionDurationMs.toLong() + resolvedDelayAfterMs)';
        $lines[] = '            if (resolvedOnComplete) {';
        $lines[] = '                Handler(Looper.getMainLooper()).post {';
        $lines[] = '                    val payload = JSONObject().apply {';
        if ($isAnimation) {
            $lines[] = '                        put("animationName", resolvedAnimFilename.removeSuffix(".lottie"))';
        } else {
            $lines[] = '                        put("animationName", "")';
        }
        $lines[] = '                        put("duration", 0.0)';
        $lines[] = '                    }';
        $lines[] = '                    NativeActionCoordinator.dispatchEvent(';
        $lines[] = '                        this@MainActivity,';
        $lines[] = '                        "S2BR\\\\\\\\MobileSplashscreen\\\\\\\\Events\\\\\\\\SplashscreenCompleted",';
        $lines[] = '                        payload.toString()';
        $lines[] = '                    )';
        $lines[] = '                }';
        $lines[] = '            }';
        $lines[] = '            showSplash = false';
        $lines[] = '        }';
        $lines[] = '';
        if ($isAnimation) {
            $lines[] = '        LaunchedEffect(loopCount) {';
            $lines[] = '            if (loopCount <= 0 || resolvedLoop || !resolvedShowProgressBar) return@LaunchedEffect';
            $lines[] = '            showProgressBar = true';
            $lines[] = '            while (!webViewReadyForSplash) {';
            $lines[] = '                val remaining = 0.88f - targetProgress';
            $lines[] = '                if (remaining > 0.001f) targetProgress += remaining * 0.15f';
            $lines[] = '                delay(400)';
            $lines[] = '            }';
            $lines[] = '            targetProgress = 1.0f';
            $lines[] = '        }';
            $lines[] = '';
        }

        // ── App icon bitmap — uses ic_launcher_foreground (plain PNG, no adaptive background) ──
        $lines[] = '        val appIconBitmap = remember {';
        $lines[] = '            runCatching {';
        $lines[] = '                android.graphics.BitmapFactory.decodeResource(resources, R.mipmap.ic_launcher_foreground)';
        $lines[] = '            }.getOrNull()';
        $lines[] = '        }';
        $lines[] = '';

        // ── Layout ──────────────────────────────────────────────────────────────
        $lines[] = '        Box(';
        $lines[] = '            modifier = Modifier';
        $lines[] = '                .fillMaxSize()';
        $lines[] = '                .alpha(alpha * exitAlpha)';
        $lines[] = '                .scale(exitScale)';
        $lines[] = '                .offset(y = exitOffsetDp)';
        $lines[] = '                .background(';
        $lines[] = '                    run {';
        $bgExpr = $hasSchedule
            ? '(dynamicConfig?.get("background") ?: staticEntry?.get("background")) as? Map<*, *>'
            : 'dynamicConfig?.get("background") as? Map<*, *>';
        $lines[] = '                        val activeBg = '.$bgExpr;
        $lines[] = '                        if (activeBg != null) {';
        $lines[] = '                            val type = activeBg["type"] as? String ?: "color"';
        $lines[] = '                            if (type == "gradient") {';
        $lines[] = '                                val colors = (activeBg["colors"] as? List<*>)?.filterIsInstance<String>()';
        $lines[] = '                                if (!colors.isNullOrEmpty()) {';
        $lines[] = '                                    return@run Brush.verticalGradient(colors.map { Color(android.graphics.Color.parseColor(it)) })';
        $lines[] = '                                }';
        $lines[] = '                            } else {';
        $lines[] = '                                val hex = activeBg["color"] as? String';
        $lines[] = '                                if (hex != null) return@run SolidColor(Color(android.graphics.Color.parseColor(hex)))';
        $lines[] = '                            }';
        $lines[] = '                        }';
        if ($hasDarkBg) {
            $darkBrush = $this->androidBrushExpression($config['theme']['dark']['background']);
            $lines[] = '                        if (isDark) return@run '.$darkBrush;
        }
        $staticBgBrush = $this->androidBrushExpression($staticBg);
        $lines[] = '                        '.$staticBgBrush;
        $lines[] = '                    }';
        $lines[] = '                )';
        $lines[] = '                .graphicsLayer { compositingStrategy = CompositingStrategy.Offscreen },';
        $lines[] = '            contentAlignment = resolvedAlignment';
        $lines[] = '        ) {';

        // ── Content — animation or text, with optional icon ─────────────────────
        if ($isAnimation) {
            $animLines = [
                'LottieAnimation(',
                '    composition = composition,',
                '    progress = { animatable.progress },',
                '    modifier = Modifier',
                '        .fillMaxWidth(resolvedSize)',
                '        .aspectRatio(1f)',
                ')',
            ];
        } else {
            $animLines = $this->androidTextLines($config['text']);
        }

        $iconLines = [
            'appIconBitmap?.let { bmp ->',
            '    Image(',
            '        bitmap = bmp.asImageBitmap(),',
            '        contentDescription = null,',
            '        modifier = Modifier',
            '            .fillMaxWidth(resolvedIconSize)',
            '            .aspectRatio(1f)',
            '            .clip(RoundedCornerShape(percent = resolvedIconRadius))',
            '    )',
            '}',
        ];

        $lines[] = '            if (resolvedShowIcon) {';
        $lines[] = '                Column(';
        $lines[] = '                    horizontalAlignment = Alignment.CenterHorizontally,';
        $lines[] = '                    verticalArrangement = Arrangement.spacedBy(24.dp)';
        $lines[] = '                ) {';
        $lines[] = '                    if (resolvedIconPosition == "top") {';
        foreach ($iconLines as $line) {
            $lines[] = '                        '.$line;
        }
        $lines[] = '                    }';
        foreach ($animLines as $line) {
            $lines[] = '                    '.$line;
        }
        $lines[] = '                    if (resolvedIconPosition != "top") {';
        foreach ($iconLines as $line) {
            $lines[] = '                        '.$line;
        }
        $lines[] = '                    }';
        $lines[] = '                }';
        $lines[] = '            } else {';
        foreach ($animLines as $line) {
            $lines[] = '                '.$line;
        }
        $lines[] = '            }';

        // ── Progress bar — drawn before canvas so circle_expand erases it cleanly ──
        $lines[] = '';
        $lines[] = '            if (showProgressBar) {';
        $lines[] = '                Box(';
        $lines[] = '                    modifier = Modifier';
        $lines[] = '                        .align(Alignment.BottomCenter)';
        $lines[] = '                        .offset(y = (-48).dp)';
        $lines[] = '                        .fillMaxWidth(0.5f)';
        $lines[] = '                        .height(3.dp),';
        $lines[] = '                    contentAlignment = resolvedProgressBarAlignment';
        $lines[] = '                ) {';
        $lines[] = '                    Box(';
        $lines[] = '                        modifier = Modifier';
        $lines[] = '                            .fillMaxSize()';
        $lines[] = '                            .clip(RoundedCornerShape(50))';
        $lines[] = '                            .background(resolvedProgressBarColor.copy(alpha = 0.15f))';
        $lines[] = '                    )';
        $lines[] = '                    Box(';
        $lines[] = '                        modifier = Modifier';
        $lines[] = '                            .fillMaxHeight()';
        $lines[] = '                            .fillMaxWidth(loadProgress)';
        $lines[] = '                            .clip(RoundedCornerShape(50))';
        $lines[] = '                            .background(resolvedProgressBarColor.copy(alpha = 0.6f))';
        $lines[] = '                    )';
        $lines[] = '                }';
        $lines[] = '            }';

        // ── Circle expand Canvas — always present, only draws during circle_expand ─
        $lines[] = '';
        $lines[] = '            Canvas(modifier = Modifier.fillMaxSize()) {';
        $lines[] = '                if (circleProgress > 0f) {';
        $lines[] = '                    val center = when (resolvedTransitionOrigin) {';
        $lines[] = '                        "top" -> Offset(size.width / 2f, 0f)';
        $lines[] = '                        "bottom" -> Offset(size.width / 2f, size.height)';
        $lines[] = '                        "top_left" -> Offset(0f, 0f)';
        $lines[] = '                        "top_right" -> Offset(size.width, 0f)';
        $lines[] = '                        "bottom_left" -> Offset(0f, size.height)';
        $lines[] = '                        "bottom_right" -> Offset(size.width, size.height)';
        $lines[] = '                        "center_left" -> Offset(0f, size.height / 2f)';
        $lines[] = '                        "center_right" -> Offset(size.width, size.height / 2f)';
        $lines[] = '                        else -> Offset(size.width / 2f, size.height / 2f)';
        $lines[] = '                    }';
        $lines[] = '                    val maxRadius = kotlin.math.sqrt(size.width * size.width + size.height * size.height)';
        $lines[] = '                    drawCircle(';
        $lines[] = '                        color = Color.Black,';
        $lines[] = '                        radius = circleProgress * maxRadius,';
        $lines[] = '                        center = center,';
        $lines[] = '                        blendMode = BlendMode.Clear';
        $lines[] = '                    )';
        $lines[] = '                }';
        $lines[] = '            }';

        $lines[] = '        }';
        $lines[] = '    }';

        return implode("\n", $lines);
    }

    /**
     * Normalise static schedule entries for embedding as JSON in native code.
     * Backgrounds are flattened to match the dynamic schedule format so both
     * sources use the same native resolution code.
     */
    protected function androidEmbedScheduleEntries(array $schedule): array
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
                // Android uses filename with extension (LottieCompositionSpec.Asset)
                $row['animation'] = pathinfo($entry['animation'], PATHINFO_FILENAME).'.lottie';
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

    protected function androidParseSchedule(array $config): array
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

    protected function buildAndroidImports(array $config): array
    {
        $isAnimation = $config['content'] === 'animation';
        $isText = $config['content'] === 'text';
        $isGradient = ($config['background']['type'] ?? 'color') === 'gradient';
        $isDiagonal = $isGradient && ($config['background']['gradient']['direction'] ?? 'vertical') === 'diagonal';
        $usesColorScheme = ($config['theme']['mode'] ?? 'auto') === 'auto';

        $imports = [
            'import androidx.compose.animation.core.animateFloatAsState',
            'import androidx.compose.animation.core.animateDpAsState',
            'import androidx.compose.animation.core.FastOutSlowInEasing',
            'import androidx.compose.animation.core.tween',
            'import androidx.compose.ui.draw.alpha',
            'import androidx.compose.ui.draw.scale',
            'import androidx.compose.foundation.layout.offset',
            'import kotlinx.coroutines.delay',
            'import android.os.Handler',
            'import android.os.Looper',
            'import com.nativephp.mobile.utils.NativeActionCoordinator',
            'import org.json.JSONObject',
            'import androidx.compose.ui.graphics.Brush',
            'import androidx.compose.ui.graphics.SolidColor',
            // Always present for circle overlay
            'import androidx.compose.foundation.Canvas',
            'import androidx.compose.ui.geometry.Offset',
            'import androidx.compose.ui.graphics.BlendMode',
            'import androidx.compose.ui.graphics.CompositingStrategy',
            'import androidx.compose.ui.graphics.graphicsLayer',
            // Always present for icon bitmap rendering
            'import androidx.compose.ui.graphics.asImageBitmap',
            // Always present for dynamic icon layout
            'import androidx.compose.foundation.layout.Column',
            'import androidx.compose.foundation.layout.Arrangement',
            'import androidx.compose.ui.res.painterResource',
            'import androidx.compose.foundation.shape.RoundedCornerShape',
            'import androidx.compose.ui.draw.clip',
            'import com.nativephp.mobile.R',
        ];

        if ($isAnimation) {
            $imports[] = 'import com.airbnb.lottie.compose.*';
        }

        if ($usesColorScheme) {
            $imports[] = 'import androidx.compose.foundation.isSystemInDarkTheme';
        }

        if ($isDiagonal) {
            // Offset already imported above; linearGradient direction uses Offset start/end
        }

        if ($isText) {
            $imports[] = 'import androidx.compose.ui.unit.sp';
            $imports[] = 'import androidx.compose.ui.text.font.FontWeight';
            $imports[] = 'import androidx.compose.ui.text.style.TextAlign';
        }

        return $imports;
    }

    protected function androidBrushExpression(array $bg): string
    {
        $type = $bg['type'] ?? 'color';

        if ($type === 'gradient') {
            $colors = $bg['gradient']['colors'] ?? ['#FFFFFF', '#000000'];
            $direction = $bg['gradient']['direction'] ?? 'vertical';
            $colorList = implode(', ', array_map(fn ($h) => $this->hexToComposeColor($h), $colors));

            return match ($direction) {
                'horizontal' => 'Brush.horizontalGradient(listOf('.$colorList.'))',
                'diagonal' => 'Brush.linearGradient(colors = listOf('.$colorList.'), start = Offset(0f, 0f), end = Offset(Float.POSITIVE_INFINITY, Float.POSITIVE_INFINITY))',
                default => 'Brush.verticalGradient(listOf('.$colorList.'))',
            };
        }

        return $this->hexToComposeColor($bg['color'] ?? '#FFFFFF');
    }

    protected function androidBackgroundModifier(array $bg, bool $isDiagonal): array
    {
        $type = $bg['type'] ?? 'color';

        if ($type === 'gradient') {
            $colors = $bg['gradient']['colors'] ?? ['#FFFFFF', '#000000'];
            $direction = $bg['gradient']['direction'] ?? 'vertical';
            $colorList = implode(', ', array_map(fn ($h) => $this->hexToComposeColor($h), $colors));

            $brush = match ($direction) {
                'horizontal' => 'Brush.horizontalGradient(listOf('.$colorList.'))',
                'diagonal' => 'Brush.linearGradient(colors = listOf('.$colorList.'), start = Offset(0f, 0f), end = Offset(Float.POSITIVE_INFINITY, Float.POSITIVE_INFINITY))',
                default => 'Brush.verticalGradient(listOf('.$colorList.'))',
            };

            return ['.background('.$brush.'),'];
        }

        return ['.background('.$this->hexToComposeColor($bg['color'] ?? '#FFFFFF').'),'];
    }

    protected function androidTextLines(array $text): array
    {
        $message = str_replace('"', '\\"', $text['message'] ?? '');
        $size = (int) ($text['size'] ?? 32);
        $weight = $this->androidTextWeight($text['weight'] ?? 'bold');
        $color = $this->hexToComposeColor($text['color'] ?? '#FFFFFF');

        return [
            'Text(',
            '    text = "'.$message.'",',
            '    fontSize = '.$size.'.sp,',
            '    fontWeight = '.$weight.',',
            '    color = '.$color.',',
            '    textAlign = TextAlign.Center,',
            '    modifier = Modifier.padding(horizontal = 32.dp)',
            ')',
        ];
    }

    protected function androidAlignment(string $position): string
    {
        return match ($position) {
            'top' => 'Alignment.TopCenter',
            'bottom' => 'Alignment.BottomCenter',
            default => 'Alignment.Center',
        };
    }

    protected function androidTextWeight(string $weight): string
    {
        return match ($weight) {
            'thin' => 'FontWeight.Thin',
            'light' => 'FontWeight.Light',
            'regular' => 'FontWeight.Normal',
            'medium' => 'FontWeight.Medium',
            'semibold' => 'FontWeight.SemiBold',
            'heavy' => 'FontWeight.ExtraBold',
            'black' => 'FontWeight.Black',
            default => 'FontWeight.Bold',
        };
    }

    protected function hexToComposeColor(string $hex): string
    {
        $hex = strtoupper(ltrim($hex, '#'));
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return 'Color(0xFF'.$hex.')';
    }

    protected function hexToAndroidColor(string $hex): string
    {
        $hex = strtoupper(ltrim($hex, '#'));
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }
}
