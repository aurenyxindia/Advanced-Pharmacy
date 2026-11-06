package `in`.paperlesspharmacy.advanced.ui.theme

import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

val PharmaTeal = Color(0xFF0D9488)
val PharmaTealDark = Color(0xFF0F766E)
val PharmaSlate = Color(0xFF0F172A)
val PharmaMuted = Color(0xFF64748B)
val PharmaBorder = Color(0x140F172A)
val PharmaSurface = Color(0xFFF8FAFC)

private val LightColors = lightColorScheme(
    primary = PharmaTeal,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFCCFBF1),
    onPrimaryContainer = PharmaTealDark,
    secondary = Color(0xFF0284C7),
    background = Color(0xFFF1F5F9),
    surface = Color.White,
    onSurface = PharmaSlate,
    onSurfaceVariant = PharmaMuted,
    outline = PharmaBorder,
    error = Color(0xFFDC2626),
)

/**
 * Titles leave color unspecified so Material surfaces (dark-green TopAppBar, etc.)
 * can apply their contentColor (white). Body/label colors stay slate for light cards.
 */
val CompactTypography = Typography(
    displayLarge = TextStyle(fontSize = 20.sp, fontWeight = FontWeight.Bold),
    titleLarge = TextStyle(fontSize = 15.sp, fontWeight = FontWeight.SemiBold),
    titleMedium = TextStyle(fontSize = 13.sp, fontWeight = FontWeight.SemiBold),
    titleSmall = TextStyle(fontSize = 12.sp, fontWeight = FontWeight.Medium),
    bodyLarge = TextStyle(fontSize = 13.sp, color = PharmaSlate),
    bodyMedium = TextStyle(fontSize = 12.sp, color = PharmaSlate),
    bodySmall = TextStyle(fontSize = 11.sp, color = PharmaMuted),
    labelLarge = TextStyle(fontSize = 12.sp, fontWeight = FontWeight.Medium, color = PharmaSlate),
    labelMedium = TextStyle(fontSize = 11.sp, fontWeight = FontWeight.Medium, color = PharmaSlate),
    labelSmall = TextStyle(fontSize = 10.sp, fontWeight = FontWeight.Medium, color = PharmaMuted),
)

val CompactButtonHeight = 36.dp
val CompactFieldHeight = 40.dp
val SalesDenseFieldHeight = 32.dp
val SalesDenseButtonHeight = 32.dp
val CompactCardPadding = 10.dp
val CompactScreenPadding = 10.dp
/** Compact form app bar content height — just enough for title text (excl. status bar). */
val CompactFormTopBarHeight = 34.dp
/** ≈1mm gap under the green header before counters / form body. */
val CompactFormHeaderGap = 4.dp

/** Dark-green app bar — always white title/icons for contrast. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun pharmaTopAppBarColors() = TopAppBarDefaults.topAppBarColors(
    containerColor = PharmaTealDark,
    scrolledContainerColor = PharmaTealDark,
    titleContentColor = Color.White,
    navigationIconContentColor = Color.White,
    actionIconContentColor = Color.White,
)

/** Explicit white for TopAppBar titles (overrides any style color). */
val PharmaOnAppBar = Color.White

@Composable
fun PharmaTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = LightColors,
        typography = CompactTypography,
        content = content,
    )
}
