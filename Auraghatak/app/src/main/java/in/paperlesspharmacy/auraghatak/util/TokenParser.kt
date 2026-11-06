package in.paperlesspharmacy.auraghatak.util

import android.net.Uri

object TokenParser {
    /** Extract AGT token from shop URL, query string, or raw hex token. */
    fun parse(input: String): String? {
        val trimmed = input.trim()
        if (trimmed.isEmpty()) return null
        if (trimmed.length in 24..64 && trimmed.all { it.isLetterOrDigit() } && !trimmed.contains("://")) {
            return trimmed
        }
        val uri = runCatching { Uri.parse(trimmed) }.getOrNull() ?: return null
        uri.getQueryParameter("token")?.takeIf { it.isNotBlank() }?.let { return it }
        val last = uri.pathSegments.lastOrNull()
        if (last != null && last.length >= 24 && last.all { it.isLetterOrDigit() }) return last
        return null
    }
}
