package com.paperlesspharmacy.auraghartak.data

/** Multi-word medicine search (e.g. "candid soap", "calpol 650"). */
object ProductSearchMatch {
    fun tokens(query: String): List<String> =
        query.trim().split(Regex("\\s+")).map { it.trim() }.filter { it.length >= 2 }

    fun matchesQuery(query: String, vararg fields: String): Boolean {
        val q = query.trim()
        if (q.isEmpty()) return true
        val haystack = fields.joinToString(" ") { it.trim() }.lowercase()
        if (haystack.isEmpty()) return false
        val parts = tokens(q)
        if (parts.isEmpty()) return haystack.contains(q.lowercase())
        return parts.all { token -> haystack.contains(token.lowercase()) }
    }
}
