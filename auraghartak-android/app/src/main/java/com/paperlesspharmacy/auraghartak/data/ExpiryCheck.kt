package com.paperlesspharmacy.auraghartak.data

import java.time.YearMonth

/**
 * Pharmacy expiry is a month: stock marked 10/26 is sellable until the end of October 2026.
 * Blank or unreadable dates are treated as not expired so valid stock is never hidden.
 */
object ExpiryCheck {
    private val yearFirst = Regex("^(\\d{4})[-/.](\\d{1,2})(?:[-/.]\\d{1,2})?")
    private val dayMonthYear = Regex("^(\\d{1,2})[-/.](\\d{1,2})[-/.](\\d{2,4})")
    private val monthYear = Regex("^(\\d{1,2})[-/.](\\d{2,4})$")

    fun isExpired(raw: String, today: YearMonth = YearMonth.now()): Boolean {
        val month = parse(raw) ?: return false
        return month.isBefore(today)
    }

    private fun parse(raw: String): YearMonth? {
        val s = raw.trim().substringBefore(' ').substringBefore('T')
        if (s.isEmpty() || s.startsWith("0000")) return null
        yearFirst.find(s)?.let { m ->
            return of(m.groupValues[1].toInt(), m.groupValues[2].toInt())
        }
        dayMonthYear.find(s)?.let { m ->
            return of(year(m.groupValues[3]), m.groupValues[2].toInt())
        }
        monthYear.find(s)?.let { m ->
            return of(year(m.groupValues[2]), m.groupValues[1].toInt())
        }
        return null
    }

    private fun year(v: String): Int = v.toInt().let { if (v.length <= 2) 2000 + it else it }

    private fun of(year: Int, month: Int): YearMonth? =
        if (month in 1..12 && year in 2000..2100) YearMonth.of(year, month) else null
}
