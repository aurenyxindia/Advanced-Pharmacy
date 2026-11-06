package com.paperlesspharmacy.auraghartak.ui

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

// Brand — aligned with Advanced Pharmacy (teal / slate enterprise)
val Teal = Color(0xFF0F766E)
val TealBright = Color(0xFF14B8A6)
val TealDeep = Color(0xFF115E59)
val Mint = Color(0xFFCCFBF1)
val MintSoft = Color(0xFFF0FDFA)
val Page = Color(0xFFF1F5F9)
val Ink = Color(0xFF0F172A)
val Mute = Color(0xFF64748B)
val Line = Color(0xFFE2E8F0)
val SurfaceMuted = Color(0xFFF8FAFC)
val Danger = Color(0xFFB91C1C)
val DangerBg = Color(0xFFFEF2F2)
val Success = Color(0xFF059669)
val SuccessBg = Color(0xFFECFDF5)

/** Full-screen auth / splash background — light so dark form text stays readable */
val AuthBackgroundBrush = Brush.verticalGradient(
    0f to MintSoft,
    0.35f to Color(0xFFE0F2F1),
    1f to Page,
)

val ScreenBackgroundBrush = Brush.verticalGradient(
    0f to Color(0xFFCCFBF1),
    0.12f to Page,
    1f to Page,
)

object AuraDimens {
    val spaceXs = 4.dp
    val spaceSm = 8.dp
    val spaceMd = 14.dp
    val spaceLg = 20.dp
    val spaceXl = 28.dp
    val minTouch = 46.dp
    val radiusSm = 10.dp
    val radiusMd = 14.dp
    val radiusLg = 16.dp
    val radiusXl = 20.dp
    val screenPad = 14.dp
}

private val scheme = lightColorScheme(
    primary = Teal,
    onPrimary = Color.White,
    primaryContainer = Mint,
    onPrimaryContainer = TealDeep,
    secondary = TealBright,
    background = Page,
    surface = Color.White,
    surfaceVariant = SurfaceMuted,
    onBackground = Ink,
    onSurface = Ink,
    onSurfaceVariant = Mute,
    outline = Line,
    error = Danger,
    onError = Color.White,
)

private val auraTypography = Typography(
    headlineLarge = TextStyle(fontSize = 24.sp, fontWeight = FontWeight.SemiBold, color = Ink, lineHeight = 30.sp),
    headlineMedium = TextStyle(fontSize = 20.sp, fontWeight = FontWeight.SemiBold, color = Ink, lineHeight = 26.sp),
    titleLarge = TextStyle(fontSize = 18.sp, fontWeight = FontWeight.SemiBold, color = Ink),
    titleMedium = TextStyle(fontSize = 15.sp, fontWeight = FontWeight.SemiBold, color = Ink),
    titleSmall = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.SemiBold, color = Ink),
    bodyLarge = TextStyle(fontSize = 15.sp, fontWeight = FontWeight.Normal, color = Ink, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Normal, color = Ink, lineHeight = 20.sp),
    bodySmall = TextStyle(fontSize = 12.sp, fontWeight = FontWeight.Normal, color = Mute, lineHeight = 16.sp),
    labelLarge = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Medium, color = Ink),
    labelMedium = TextStyle(fontSize = 12.sp, fontWeight = FontWeight.Medium, color = Mute),
    labelSmall = TextStyle(fontSize = 11.sp, fontWeight = FontWeight.Medium, color = Mute, letterSpacing = 0.5.sp),
)

private val auraShapes = Shapes(
    extraSmall = RoundedCornerShape(AuraDimens.radiusSm),
    small = RoundedCornerShape(AuraDimens.radiusSm),
    medium = RoundedCornerShape(AuraDimens.radiusMd),
    large = RoundedCornerShape(AuraDimens.radiusLg),
    extraLarge = RoundedCornerShape(AuraDimens.radiusXl),
)

@Composable
fun AuraTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = scheme,
        typography = auraTypography,
        shapes = auraShapes,
        content = content,
    )
}

@Composable
fun auraTextFieldColors() = OutlinedTextFieldDefaults.colors(
    focusedBorderColor = Teal,
    unfocusedBorderColor = Line,
    focusedLabelColor = TealDeep,
    unfocusedLabelColor = Mute,
    cursorColor = Teal,
    focusedTextColor = Ink,
    unfocusedTextColor = Ink,
    focusedContainerColor = Color.White,
    unfocusedContainerColor = Color.White,
    disabledTextColor = Mute,
    disabledContainerColor = SurfaceMuted,
)
