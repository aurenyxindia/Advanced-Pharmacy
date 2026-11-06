package com.paperlesspharmacy.auraghartak.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material.icons.outlined.Remove
import androidx.compose.material.icons.outlined.LocalPharmacy
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

@Composable
fun BrandMark(modifier: Modifier = Modifier, large: Boolean = false) {
    val outer = if (large) 88.dp else 72.dp
    val inner = if (large) 44.dp else 36.dp
    Column(modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            Modifier
                .size(outer)
                .clip(CircleShape)
                .background(Color.White.copy(alpha = 0.95f))
                .border(3.dp, Mint, CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Box(
                Modifier
                    .size(outer - 16.dp)
                    .clip(CircleShape)
                    .background(Brush.linearGradient(listOf(TealBright, TealDeep))),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Outlined.LocalPharmacy,
                    contentDescription = "AuraGharTak",
                    tint = Color.White,
                    modifier = Modifier.size(inner),
                )
            }
        }
        Spacer(Modifier.height(AuraDimens.spaceSm))
        Text(
            "AuraGharTak",
            color = TealDeep,
            fontWeight = FontWeight.Bold,
            fontSize = if (large) 26.sp else 22.sp,
        )
        Text(
            "Paperless Pharmacy",
            color = Mute,
            fontSize = 12.sp,
            letterSpacing = 0.8.sp,
        )
    }
}

@Composable
fun PrimaryButton(
    text: String,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    loading: Boolean = false,
    onClick: () -> Unit,
) {
    val shape = MaterialTheme.shapes.medium
    Box(
        modifier = modifier
            .fillMaxWidth()
            .height(AuraDimens.minTouch)
            .clip(shape)
            .background(
                if (enabled && !loading) {
                    Brush.horizontalGradient(listOf(TealBright, TealDeep))
                } else {
                    Brush.horizontalGradient(listOf(Teal.copy(0.35f), Teal.copy(0.35f)))
                },
            )
            .clickable(enabled = enabled && !loading, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (loading) {
            CircularProgressIndicator(modifier = Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp)
        } else {
            Text(text, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = Color.White)
        }
    }
}

@Composable
fun SecondaryButton(text: String, modifier: Modifier = Modifier, onClick: () -> Unit) {
    OutlinedButton(
        onClick = onClick,
        modifier = modifier
            .fillMaxWidth()
            .height(AuraDimens.minTouch),
        shape = MaterialTheme.shapes.medium,
        colors = ButtonDefaults.outlinedButtonColors(contentColor = TealDeep),
        border = ButtonDefaults.outlinedButtonBorder.copy(brush = SolidColor(Line)),
    ) {
        Text(text, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
fun AuraHeroHeader(
    modifier: Modifier = Modifier,
    eyebrow: String? = null,
    title: String,
    subtitle: String? = null,
    trailing: @Composable (() -> Unit)? = null,
    content: @Composable (() -> Unit)? = null,
) {
    Column(
        modifier
            .fillMaxWidth()
            .background(Brush.verticalGradient(listOf(TealDeep, Teal)))
            .padding(horizontal = AuraDimens.screenPad, vertical = 10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                if (!eyebrow.isNullOrBlank()) {
                    Text(
                        eyebrow.uppercase(),
                        fontSize = 10.sp,
                        fontWeight = FontWeight.Medium,
                        color = Mint,
                        letterSpacing = 1.sp,
                        maxLines = 1,
                    )
                }
                Text(
                    title,
                    style = MaterialTheme.typography.titleLarge,
                    color = Color.White,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            trailing?.invoke()
        }
        if (!subtitle.isNullOrBlank()) {
            Text(
                subtitle,
                style = MaterialTheme.typography.bodySmall,
                color = Color.White.copy(alpha = 0.88f),
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.padding(top = 2.dp),
            )
        }
        if (content != null) {
            Spacer(Modifier.height(AuraDimens.spaceSm))
            content()
        }
    }
}

@Composable
fun AppTopBar(
    title: String,
    subtitle: String? = null,
    onBack: (() -> Unit)? = null,
    trailing: @Composable (() -> Unit)? = null,
) {
    Row(
        Modifier
            .fillMaxWidth()
            .background(Teal)
            .padding(start = 0.dp, end = AuraDimens.spaceMd, top = 2.dp, bottom = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (onBack != null) {
            IconButton(onClick = onBack, modifier = Modifier.size(AuraDimens.minTouch)) {
                Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Back", tint = Color.White)
            }
        } else {
            Spacer(Modifier.width(AuraDimens.spaceMd))
        }
        Column(Modifier.weight(1f)) {
            Text(
                title,
                style = MaterialTheme.typography.titleMedium,
                color = Color.White,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            if (!subtitle.isNullOrBlank()) {
                Text(
                    subtitle,
                    style = MaterialTheme.typography.bodySmall,
                    color = Color.White.copy(alpha = 0.85f),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
        trailing?.invoke()
    }
}

@Composable
fun ScreenBar(title: String, onBack: (() -> Unit)? = null) {
    AppTopBar(title = title, onBack = onBack)
}

@Composable
fun SearchField(value: String, hint: String, onChange: (String) -> Unit) {
    Surface(
        modifier = Modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.medium,
        color = Color.White,
        shadowElevation = 2.dp,
    ) {
        Row(
            Modifier.padding(horizontal = 12.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Outlined.Search, contentDescription = null, tint = Mute, modifier = Modifier.size(20.dp))
            Box(Modifier.padding(start = AuraDimens.spaceSm).weight(1f)) {
                if (value.isEmpty()) {
                    Text(
                        hint,
                        style = MaterialTheme.typography.bodyMedium,
                        color = Mute,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
                BasicTextField(
                    value = value,
                    onValueChange = onChange,
                    singleLine = true,
                    textStyle = MaterialTheme.typography.bodyMedium.copy(color = Ink),
                    cursorBrush = SolidColor(Teal),
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        }
    }
}

@Composable
fun SelectableChip(label: String, selected: Boolean, onClick: () -> Unit) {
    Surface(
        onClick = onClick,
        shape = RoundedCornerShape(999.dp),
        color = if (selected) Teal else Color.White,
        border = if (selected) null else androidx.compose.foundation.BorderStroke(1.dp, Line),
    ) {
        Text(
            label,
            modifier = Modifier.padding(horizontal = 12.dp, vertical = 6.dp),
            color = if (selected) Color.White else Ink,
            fontSize = 12.sp,
            fontWeight = FontWeight.Medium,
            maxLines = 1,
        )
    }
}

@Composable
fun TextActionChip(label: String, active: Boolean, onClick: () -> Unit) {
    Text(
        label,
        color = TealDeep,
        fontWeight = FontWeight.SemiBold,
        fontSize = 12.sp,
        maxLines = 1,
        modifier = Modifier
            .clip(RoundedCornerShape(999.dp))
            .background(if (active) Mint else MintSoft)
            .clickable(onClick = onClick)
            .padding(horizontal = 10.dp, vertical = 6.dp),
    )
}

@Composable
fun ErrorBanner(text: String, onRetry: (() -> Unit)? = null) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(MaterialTheme.shapes.medium)
            .background(DangerBg)
            .border(1.dp, Danger.copy(alpha = 0.15f), MaterialTheme.shapes.medium)
            .padding(horizontal = AuraDimens.spaceMd, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(text, color = Danger, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
        if (onRetry != null) {
            TextButton(onClick = onRetry) { Text("Retry", color = Danger, fontWeight = FontWeight.SemiBold) }
        }
    }
}

@Composable
fun StatusChip(status: String) {
    val (bg, fg) = when (status.lowercase()) {
        "delivered" -> SuccessBg to Success
        "cancelled" -> DangerBg to Danger
        "out_for_delivery" -> Color(0xFFFEF3C7) to Color(0xFF92400E)
        "packed", "confirmed" -> Color(0xFFDBEAFE) to Color(0xFF1D4ED8)
        else -> Mint to TealDeep
    }
    Text(
        statusLabel(status),
        color = fg,
        fontSize = 12.sp,
        fontWeight = FontWeight.SemiBold,
        modifier = Modifier
            .clip(RoundedCornerShape(999.dp))
            .background(bg)
            .padding(horizontal = 10.dp, vertical = 5.dp),
    )
}

@Composable
fun QtyStepper(qty: Int, onMinus: () -> Unit, onPlus: () -> Unit, compact: Boolean = false) {
    val button = if (compact) 32.dp else 40.dp
    val icon = if (compact) 16.dp else 18.dp
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.Center,
        modifier = Modifier.fillMaxWidth(),
    ) {
        StepButton(Icons.Outlined.Remove, onMinus, button, icon, "Decrease quantity")
        Text(
            qty.toString(),
            modifier = Modifier.width(if (compact) 28.dp else 36.dp),
            fontWeight = FontWeight.SemiBold,
            fontSize = if (compact) 14.sp else 16.sp,
            textAlign = TextAlign.Center,
        )
        StepButton(Icons.Outlined.Add, onPlus, button, icon, "Increase quantity")
    }
}

@Composable
private fun StepButton(
    icon: ImageVector,
    onClick: () -> Unit,
    button: androidx.compose.ui.unit.Dp,
    iconSize: androidx.compose.ui.unit.Dp,
    contentDescription: String,
) {
    Box(
        modifier = Modifier
            .size(button)
            .clip(CircleShape)
            .background(MintSoft)
            .border(1.dp, Mint, CircleShape)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, contentDescription = contentDescription, tint = TealDeep, modifier = Modifier.size(iconSize))
    }
}

@Composable
fun ScheduleChip(schedule: String) {
    val label = schedule.trim()
    if (label.isEmpty() || label.equals("none", true) || label.equals("normal", true)) return
    Text(
        label.uppercase(),
        color = Color(0xFF92400E),
        fontSize = 10.sp,
        fontWeight = FontWeight.Bold,
        modifier = Modifier
            .clip(RoundedCornerShape(6.dp))
            .background(Color(0xFFFEF3C7))
            .padding(horizontal = 6.dp, vertical = 2.dp),
    )
}

/** Available quantity on the medicine product card (number only). */
@Composable
fun StockQtyChip(qty: Double, modifier: Modifier = Modifier) {
    if (qty <= 0) return
    val label = "Qty · ${stockQtyNumber(qty)}"
    val bg = Mint
    val fg = TealDeep
    Text(
        label,
        color = fg,
        fontSize = 10.sp,
        fontWeight = FontWeight.Bold,
        maxLines = 1,
        modifier = modifier
            .clip(RoundedCornerShape(6.dp))
            .background(bg)
            .padding(horizontal = 8.dp, vertical = 3.dp),
    )
}

@Composable
fun EmptyHint(title: String, body: String, icon: ImageVector = Icons.Outlined.Inbox) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = AuraDimens.spaceXl, horizontal = AuraDimens.spaceLg),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(
            Modifier
                .size(56.dp)
                .clip(CircleShape)
                .background(SurfaceMuted),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, contentDescription = null, tint = Mute, modifier = Modifier.size(28.dp))
        }
        Spacer(Modifier.height(AuraDimens.spaceMd))
        Text(title, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
        Spacer(Modifier.height(AuraDimens.spaceSm))
        Text(body, style = MaterialTheme.typography.bodyMedium, color = Mute, textAlign = TextAlign.Center)
    }
}

@Composable
fun LoadingState(message: String = "Loading…") {
    Column(
        Modifier.fillMaxSize(),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        CircularProgressIndicator(color = Teal, strokeWidth = 2.5.dp)
        Spacer(Modifier.height(AuraDimens.spaceMd))
        Text(message, style = MaterialTheme.typography.bodyMedium, color = Mute)
    }
}

@Composable
fun FormField(
    label: String,
    value: String,
    singleLine: Boolean = true,
    onChange: (String) -> Unit,
) {
    Column(Modifier.fillMaxWidth().padding(vertical = AuraDimens.spaceSm)) {
        Text(label, style = MaterialTheme.typography.labelMedium, color = Mute)
        Spacer(Modifier.height(AuraDimens.spaceSm))
        OutlinedTextField(
            value = value,
            onValueChange = onChange,
            modifier = Modifier.fillMaxWidth(),
            singleLine = singleLine,
            minLines = if (singleLine) 1 else 3,
            shape = MaterialTheme.shapes.medium,
            colors = auraTextFieldColors(),
            textStyle = MaterialTheme.typography.bodyLarge.copy(color = Ink),
        )
    }
}

@Composable
fun SoftCard(modifier: Modifier = Modifier, elevated: Boolean = false, content: @Composable () -> Unit) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.large,
        color = Color.White,
        tonalElevation = 0.dp,
        shadowElevation = if (elevated) 12.dp else 2.dp,
        border = androidx.compose.foundation.BorderStroke(1.dp, if (elevated) Teal.copy(0.12f) else Line.copy(alpha = 0.8f)),
    ) {
        Column(Modifier.padding(if (elevated) AuraDimens.spaceLg else AuraDimens.spaceMd)) { content() }
    }
}

@Composable
fun AuthFormColumn(content: @Composable () -> Unit) {
    Column(
        Modifier
            .fillMaxWidth()
            .widthIn(max = 420.dp)
            .padding(horizontal = AuraDimens.spaceLg),
        horizontalAlignment = Alignment.CenterHorizontally,
        content = { content() },
    )
}

@Composable
fun ListCard(modifier: Modifier = Modifier, onClick: (() -> Unit)? = null, content: @Composable () -> Unit) {
    val shape = MaterialTheme.shapes.large
    val border = androidx.compose.foundation.BorderStroke(1.dp, Line.copy(alpha = 0.6f))
    val inner = @Composable {
        Column(Modifier.padding(AuraDimens.spaceMd)) { content() }
    }
    if (onClick != null) {
        Surface(
            onClick = onClick,
            modifier = modifier.fillMaxWidth(),
            shape = shape,
            color = Color.White,
            shadowElevation = 1.dp,
            border = border,
            content = inner,
        )
    } else {
        Surface(
            modifier = modifier.fillMaxWidth(),
            shape = shape,
            color = Color.White,
            shadowElevation = 1.dp,
            border = border,
            content = inner,
        )
    }
}

@Composable
fun Avatar(letter: String, size: androidx.compose.ui.unit.Dp = 48.dp) {
    Box(
        modifier = Modifier
            .size(size)
            .clip(CircleShape)
            .background(MintSoft)
            .border(1.dp, Mint, CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            letter.take(1).uppercase(),
            color = TealDeep,
            fontWeight = FontWeight.Bold,
            fontSize = (size.value * 0.38f).sp,
        )
    }
}

@Composable
fun TwoLine(title: String, subtitle: String) {
    Column(Modifier.padding(start = 12.dp), verticalArrangement = Arrangement.Center) {
        Text(title, fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
        if (subtitle.isNotBlank()) {
            Text(subtitle, color = Mute, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
fun CartSummaryBar(itemCount: Int, totalLabel: String, actionLabel: String, onAction: () -> Unit) {
    Surface(
        modifier = Modifier.fillMaxWidth(),
        color = Color.White,
        shadowElevation = 8.dp,
        tonalElevation = 2.dp,
    ) {
        Row(
            Modifier
                .fillMaxWidth()
                .padding(horizontal = AuraDimens.spaceMd, vertical = AuraDimens.spaceMd),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text("$itemCount items", style = MaterialTheme.typography.bodySmall)
                Text(totalLabel, style = MaterialTheme.typography.titleMedium, color = Ink)
            }
            Button(
                onClick = onAction,
                shape = MaterialTheme.shapes.medium,
                colors = ButtonDefaults.buttonColors(containerColor = Teal),
                modifier = Modifier.height(44.dp),
            ) {
                Text(actionLabel, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
fun SectionDivider() {
    HorizontalDivider(color = Line, thickness = 1.dp, modifier = Modifier.padding(vertical = AuraDimens.spaceSm))
}

@Composable
fun ProfileInfoRow(label: String, value: String) {
    if (value.isBlank()) return
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
        Text(label, style = MaterialTheme.typography.labelMedium, modifier = Modifier.weight(0.38f))
        Text(value, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(0.62f))
    }
}
