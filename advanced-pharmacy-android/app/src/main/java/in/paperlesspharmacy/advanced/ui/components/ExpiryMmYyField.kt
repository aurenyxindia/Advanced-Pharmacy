package `in`.paperlesspharmacy.advanced.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.OffsetMapping
import androidx.compose.ui.text.input.TransformedText
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaMuted
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaSlate
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTeal
import `in`.paperlesspharmacy.advanced.ui.util.formatExpiryMmYy
import `in`.paperlesspharmacy.advanced.ui.util.parseExpiryYm
import java.time.LocalDate

/**
 * MM/YY pack expiry — store digits only; slash is visual so the caret never jumps
 * (fixes typing “27” becoming “72”).
 */
private object ExpiryMmYyVisual : VisualTransformation {
    override fun filter(text: AnnotatedString): TransformedText {
        val d = text.text.filter { it.isDigit() }.take(4)
        val out = buildString {
            d.forEachIndexed { i, c ->
                if (i == 2) append('/')
                append(c)
            }
        }
        val map = object : OffsetMapping {
            override fun originalToTransformed(offset: Int): Int {
                val o = offset.coerceIn(0, d.length)
                return if (o <= 2) o else o + 1
            }

            override fun transformedToOriginal(offset: Int): Int = when {
                offset <= 2 -> offset.coerceAtMost(d.length)
                else -> (offset - 1).coerceIn(0, d.length)
            }
        }
        return TransformedText(AnnotatedString(out), map)
    }
}

/** YYYY-MM → up to 4 digits MMYY. */
fun expiryYmToDigits(ym: String): String {
    val display = formatExpiryMmYy(ym)
    return if (display.length == 5) display.filter { it.isDigit() } else ""
}

/** Partial/full MMYY digits → YYYY-MM when complete & valid. */
fun expiryDigitsToYm(digits: String): String {
    val d = digits.filter { it.isDigit() }.take(4)
    if (d.length < 4) return ""
    return parseExpiryYm("${d.take(2)}/${d.drop(2)}")
}

private fun digitsToDisplay(digits: String): String {
    val d = digits.filter { it.isDigit() }.take(4)
    return if (d.length > 2) d.take(2) + "/" + d.drop(2) else d
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ExpiryMonthYearPickerDialog(
    initialYm: String,
    onDismiss: () -> Unit,
    onConfirm: (yyyyMm: String) -> Unit,
) {
    val now = remember { LocalDate.now() }
    val init = remember(initialYm) {
        val ym = parseExpiryYm(initialYm)
        if (ym.length >= 7) {
            (ym.substring(5, 7).toIntOrNull() ?: now.monthValue) to
                (ym.substring(0, 4).toIntOrNull() ?: now.year)
        } else now.monthValue to now.year
    }
    var month by remember { mutableIntStateOf(init.first.coerceIn(1, 12)) }
    var year by remember { mutableIntStateOf(init.second) }
    val years = remember { ((now.year - 1)..(now.year + 10)).toList() }
    var monthOpen by remember { mutableStateOf(false) }
    var yearOpen by remember { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Select expiry", style = MaterialTheme.typography.titleSmall) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("Pick month and year", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ExposedDropdownMenuBox(
                        expanded = monthOpen,
                        onExpandedChange = { monthOpen = it },
                        modifier = Modifier.weight(1f),
                    ) {
                        OutlinedTextField(
                            value = month.toString().padStart(2, '0'),
                            onValueChange = {},
                            readOnly = true,
                            label = { Text("Month") },
                            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(monthOpen) },
                            modifier = Modifier.menuAnchor().fillMaxWidth(),
                            singleLine = true,
                        )
                        ExposedDropdownMenu(expanded = monthOpen, onDismissRequest = { monthOpen = false }) {
                            (1..12).forEach { m ->
                                DropdownMenuItem(
                                    text = { Text(m.toString().padStart(2, '0')) },
                                    onClick = { month = m; monthOpen = false },
                                )
                            }
                        }
                    }
                    ExposedDropdownMenuBox(
                        expanded = yearOpen,
                        onExpandedChange = { yearOpen = it },
                        modifier = Modifier.weight(1f),
                    ) {
                        OutlinedTextField(
                            value = year.toString(),
                            onValueChange = {},
                            readOnly = true,
                            label = { Text("Year") },
                            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(yearOpen) },
                            modifier = Modifier.menuAnchor().fillMaxWidth(),
                            singleLine = true,
                        )
                        ExposedDropdownMenu(expanded = yearOpen, onDismissRequest = { yearOpen = false }) {
                            years.forEach { y ->
                                DropdownMenuItem(
                                    text = { Text(y.toString()) },
                                    onClick = { year = y; yearOpen = false },
                                )
                            }
                        }
                    }
                }
                Text(
                    "Selected: ${month.toString().padStart(2, '0')}/${year.toString().takeLast(2)}",
                    style = MaterialTheme.typography.bodySmall,
                    color = PharmaTeal,
                )
            }
        },
        confirmButton = {
            TextButton(onClick = {
                onConfirm("${year}-${month.toString().padStart(2, '0')}")
            }) { Text("OK") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

/** Labeled MM/YY field + calendar picker (purchase / opening stock). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ExpiryMmYyFormField(
    label: String,
    displayText: String,
    onDisplayText: (String) -> Unit,
    modifier: Modifier = Modifier,
    isError: Boolean = false,
    showPicker: Boolean = true,
) {
    var digits by remember { mutableStateOf(displayText.filter { it.isDigit() }.take(4)) }
    var picker by remember { mutableStateOf(false) }
    var focused by remember { mutableStateOf(false) }

    LaunchedEffect(displayText) {
        val parentDigits = displayText.filter { it.isDigit() }.take(4)
        if (!focused && parentDigits != digits) digits = parentDigits
    }

    val borderColor = when {
        isError -> MaterialTheme.colorScheme.error
        focused -> PharmaTeal
        else -> Color(0xFFCBD5E1)
    }

    Column(modifier) {
        Text(
            label,
            style = MaterialTheme.typography.labelSmall,
            color = if (isError) MaterialTheme.colorScheme.error else PharmaMuted,
            maxLines = 1,
        )
        Spacer(Modifier.height(1.dp))
        Row(
            Modifier
                .fillMaxWidth()
                .height(36.dp)
                .clip(RoundedCornerShape(6.dp))
                .background(Color.White)
                .border(1.dp, borderColor, RoundedCornerShape(6.dp))
                .padding(start = 8.dp, end = 2.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            BasicTextField(
                value = digits,
                onValueChange = { raw ->
                    val next = raw.filter { it.isDigit() }.take(4)
                    digits = next
                    onDisplayText(digitsToDisplay(next))
                },
                singleLine = true,
                textStyle = MaterialTheme.typography.bodySmall.copy(color = PharmaSlate),
                cursorBrush = SolidColor(PharmaTeal),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Next),
                visualTransformation = ExpiryMmYyVisual,
                modifier = Modifier
                    .weight(1f)
                    .onFocusChanged { focused = it.isFocused },
                decorationBox = { inner ->
                    Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.CenterStart) {
                        if (digits.isEmpty()) {
                            Text("MM/YY", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                        }
                        inner()
                    }
                },
            )
            if (showPicker) {
                IconButton(onClick = { picker = true }, modifier = Modifier.size(32.dp)) {
                    Icon(Icons.Default.CalendarMonth, "Select expiry", Modifier.size(18.dp), tint = PharmaTeal)
                }
            }
        }
    }

    if (picker) {
        ExpiryMonthYearPickerDialog(
            initialYm = parseExpiryYm(displayText).ifBlank { expiryDigitsToYm(digits) },
            onDismiss = { picker = false },
            onConfirm = { ym ->
                digits = expiryYmToDigits(ym)
                onDisplayText(formatExpiryMmYy(ym))
                picker = false
            },
        )
    }
}

/** Compact grid cell for line-item expiry. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ExpiryMmYyCell(
    ym: String,
    onYm: (String) -> Unit,
    width: Dp,
    showPicker: Boolean = true,
) {
    var digits by remember { mutableStateOf(expiryYmToDigits(ym)) }
    var picker by remember { mutableStateOf(false) }
    var focused by remember { mutableStateOf(false) }

    LaunchedEffect(ym) {
        if (!focused) {
            val fromYm = expiryYmToDigits(ym)
            if (fromYm != digits && (ym.isBlank() || expiryDigitsToYm(digits) != ym)) {
                digits = fromYm
            }
        }
    }

    Row(
        Modifier
            .width(width)
            .height(30.dp)
            .clip(RoundedCornerShape(5.dp))
            .background(Color.White)
            .border(1.dp, Color(0xFFE2E8F0), RoundedCornerShape(5.dp))
            .padding(start = 3.dp, end = 0.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        BasicTextField(
            value = digits,
            onValueChange = { raw ->
                val next = raw.filter { it.isDigit() }.take(4)
                digits = next
                onYm(expiryDigitsToYm(next))
            },
            singleLine = true,
            textStyle = MaterialTheme.typography.labelSmall.copy(
                color = PharmaSlate,
                textAlign = TextAlign.Start,
            ),
            cursorBrush = SolidColor(PharmaTeal),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Next),
            visualTransformation = ExpiryMmYyVisual,
            modifier = Modifier
                .weight(1f)
                .onFocusChanged { focused = it.isFocused },
            decorationBox = { inner ->
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.CenterStart) {
                    if (digits.isEmpty()) {
                        Text("MM/YY", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    }
                    inner()
                }
            },
        )
        if (showPicker) {
            IconButton(onClick = { picker = true }, modifier = Modifier.size(26.dp)) {
                Icon(Icons.Default.CalendarMonth, "Select", Modifier.size(14.dp), tint = PharmaTeal)
            }
        }
    }

    if (picker) {
        ExpiryMonthYearPickerDialog(
            initialYm = ym.ifBlank { expiryDigitsToYm(digits) },
            onDismiss = { picker = false },
            onConfirm = { picked ->
                digits = expiryYmToDigits(picked)
                onYm(picked)
                picker = false
            },
        )
    }
}
