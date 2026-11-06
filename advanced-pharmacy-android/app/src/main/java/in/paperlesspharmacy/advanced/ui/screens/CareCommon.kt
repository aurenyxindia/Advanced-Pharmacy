package `in`.paperlesspharmacy.advanced.ui.screens

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaBorder
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaMuted
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaSlate
import java.io.File

internal fun careErr(t: Throwable, fallback: String): String = t.message?.takeIf { it.isNotBlank() } ?: fallback

internal fun careMoney(v: Double): String = "₹" + rptMoney(v)

/** dd-MM-yyyy from yyyy-MM-dd[ HH:mm:ss]. */
internal fun careDate(raw: String): String {
    val m = Regex("""^(\d{4})-(\d{2})-(\d{2})""").find(raw.trim()) ?: return raw.ifBlank { "—" }
    return "${m.groupValues[3]}-${m.groupValues[2]}-${m.groupValues[1]}"
}

/** dd-MM-yyyy HH:mm from yyyy-MM-dd HH:mm:ss. */
internal fun careDateTime(raw: String): String {
    val t = raw.trim()
    if (t.isBlank()) return "—"
    val time = Regex("""[ T](\d{2}:\d{2})""").find(t)?.groupValues?.get(1)
    return careDate(t) + (time?.let { " $it" } ?: "")
}

internal fun carePhoneDigits(phone: String): String = phone.filter { it.isDigit() }.takeLast(10)

internal fun careDial(context: Context, phone: String) {
    val digits = carePhoneDigits(phone)
    if (digits.isEmpty()) return
    runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$digits")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}

/** Opens a WhatsApp chat with [text] prefilled; returns an error message when WhatsApp can't be opened. */
internal fun careWhatsApp(context: Context, phone: String, text: String): String? {
    val digits = carePhoneDigits(phone)
    if (digits.length < 10) return "Enter a valid 10-digit mobile number."
    return runCatching {
        val uri = Uri.parse("https://wa.me/91$digits?text=" + Uri.encode(text))
        context.startActivity(Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }.exceptionOrNull()?.let { "WhatsApp is not available on this phone." }
}

internal fun careOpenPdf(context: Context, bytes: ByteArray, fileName: String): String? =
    runCatching {
        val file = File(context.cacheDir, fileName).apply { writeBytes(bytes) }
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
        context.startActivity(
            Intent(Intent.ACTION_VIEW).apply {
                setDataAndType(uri, "application/pdf")
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
            },
        )
    }.exceptionOrNull()?.let { "No PDF viewer found on this phone." }

internal data class CareKpiItem(
    val label: String,
    val value: String,
    val color: Color,
    val icon: ImageVector,
    val selected: Boolean = false,
    val onClick: (() -> Unit)? = null,
)

/** KPI tiles: 2 per row on phones, all in one row on tablets. */
@Composable
internal fun CareKpiGrid(items: List<CareKpiItem>) {
    val wide = LocalConfiguration.current.screenWidthDp >= 600
    val perRow = if (wide) items.size.coerceAtLeast(1) else 2
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        items.chunked(perRow).forEach { row ->
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                row.forEach { k -> CareKpiTile(k, Modifier.weight(1f)) }
                repeat(perRow - row.size) { Spacer(Modifier.weight(1f)) }
            }
        }
    }
}

@Composable
private fun CareKpiTile(k: CareKpiItem, modifier: Modifier) {
    Surface(
        shape = RoundedCornerShape(10.dp),
        color = if (k.selected) k.color.copy(alpha = 0.08f) else Color.White,
        border = BorderStroke(if (k.selected) 1.5.dp else 1.dp, if (k.selected) k.color else PharmaBorder),
        modifier = modifier.clip(RoundedCornerShape(10.dp)).then(if (k.onClick != null) Modifier.clickable { k.onClick.invoke() } else Modifier),
    ) {
        Row(Modifier.padding(horizontal = 10.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.size(30.dp).clip(RoundedCornerShape(8.dp)).background(k.color.copy(alpha = 0.12f)),
                contentAlignment = Alignment.Center,
            ) { Icon(k.icon, null, Modifier.size(17.dp), tint = k.color) }
            Spacer(Modifier.width(8.dp))
            Column(Modifier.weight(1f)) {
                Text(k.label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(k.value, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

@Composable
internal fun CareBadge(text: String, fg: Color, bg: Color = fg.copy(alpha = 0.12f)) {
    Text(
        text,
        style = MaterialTheme.typography.labelSmall,
        fontWeight = FontWeight.SemiBold,
        color = fg,
        maxLines = 1,
        modifier = Modifier.clip(RoundedCornerShape(10.dp)).background(bg).padding(horizontal = 7.dp, vertical = 2.dp),
    )
}

@Composable
internal fun CareConfirmDialog(
    title: String,
    text: String,
    confirmLabel: String,
    danger: Boolean,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title, style = MaterialTheme.typography.titleMedium) },
        text = { Text(text, style = MaterialTheme.typography.bodyMedium) },
        confirmButton = {
            TextButton(onClick = onConfirm) {
                Text(confirmLabel, color = if (danger) Color(0xFFB91C1C) else MaterialTheme.colorScheme.primary, fontWeight = FontWeight.SemiBold)
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
        containerColor = Color.White,
    )
}

/** Label / value pair used in detail sheets. */
@Composable
internal fun CareInfo(label: String, value: String, modifier: Modifier = Modifier, valueColor: Color = PharmaSlate) {
    Column(modifier) {
        Text(label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        Text(value.ifBlank { "—" }, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = valueColor)
    }
}

@Composable
internal fun CareEmpty(icon: ImageVector, text: String, modifier: Modifier = Modifier) {
    Box(modifier.fillMaxWidth().padding(vertical = 32.dp), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(icon, null, Modifier.size(32.dp), tint = PharmaMuted)
            Spacer(Modifier.height(6.dp))
            Text(text, style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
        }
    }
}
