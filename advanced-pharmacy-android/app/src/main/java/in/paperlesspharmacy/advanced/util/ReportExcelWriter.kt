package `in`.paperlesspharmacy.advanced.util

import android.app.DownloadManager
import android.content.ContentValues
import android.content.Context
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import androidx.core.content.FileProvider
import java.io.ByteArrayOutputStream
import java.io.File
import java.util.zip.ZipEntry
import java.util.zip.ZipOutputStream

/**
 * Styled .xlsx matching the web ReportExcelService: pharmacy letterhead, navy title bar,
 * multicolour column headers, thin borders, zebra rows, #,##0.00 numbers, totals row, frozen header.
 */
object ReportExcelWriter {
    const val MIME = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"

    private val headerColors = listOf(
        "FF2563EB", "FF059669", "FFD97706", "FF7C3AED", "FF0284C7",
        "FFDC2626", "FF16A34A", "FF2563EB", "FF9333EA", "FFEA580C",
        "FF0891B2", "FFBE185D",
    )

    // cellXfs indexes (see stylesXml)
    private const val XF_NAME = 1
    private const val XF_LETTER = 2
    private const val XF_TITLE = 3
    private const val XF_HEADER0 = 4
    private const val XF_LEFT = 16
    private const val XF_RIGHT = 17
    private const val XF_NUM = 18
    private const val XF_LEFT_ALT = 19
    private const val XF_RIGHT_ALT = 20
    private const val XF_NUM_ALT = 21
    private const val XF_FOOT_LEFT = 22
    private const val XF_FOOT_NUM = 23
    private const val XF_NODATA = 24

    fun build(
        reportTitle: String,
        sheetName: String,
        headers: List<String>,
        rows: List<List<Any?>>,
        numericColumnStart: Int,
        footer: List<Any?>?,
        letterhead: List<String>,
    ): ByteArray {
        val colCount = maxOf(1, headers.size)
        val hasData = rows.isNotEmpty()
        val sizing = rows.toMutableList()
        if (hasData && footer != null && footer.size == colCount) sizing += footer
        val widths = (0 until colCount).map { c ->
            var maxLen = headers.getOrNull(c)?.length ?: 0
            sizing.forEach { r -> val len = r.getOrNull(c)?.let { plain(it).length } ?: 0; if (len > maxLen) maxLen = len }
            (maxLen + 5).coerceIn(12, 55)
        }

        val merges = mutableListOf<String>()
        val sb = StringBuilder()
        var rowNum = 1
        val lastCol = colName(colCount)

        letterhead.forEachIndexed { i, line ->
            val xf = if (i == 0) XF_NAME else XF_LETTER
            sb.append("<row r=\"$rowNum\" ht=\"${if (i == 0) 24 else 16}\" customHeight=\"1\">")
            sb.append(strCell("A$rowNum", line, xf))
            sb.append("</row>")
            if (colCount > 1) merges += "A$rowNum:$lastCol$rowNum"
            rowNum++
        }

        sb.append("<row r=\"$rowNum\" ht=\"22\" customHeight=\"1\">")
        sb.append(strCell("A$rowNum", reportTitle, XF_TITLE))
        for (c in 2..colCount) sb.append("<c r=\"${colName(c)}$rowNum\" s=\"$XF_TITLE\"/>")
        sb.append("</row>")
        if (colCount > 1) merges += "A$rowNum:$lastCol$rowNum"
        rowNum++

        sb.append("<row r=\"$rowNum\" ht=\"22\" customHeight=\"1\">")
        headers.forEachIndexed { c, h -> sb.append(strCell("${colName(c + 1)}$rowNum", h, XF_HEADER0 + c % headerColors.size)) }
        sb.append("</row>")
        val freezeAt = rowNum
        rowNum++

        if (!hasData) {
            sb.append("<row r=\"$rowNum\">")
            sb.append(strCell("A$rowNum", "No data", XF_NODATA))
            for (c in 2..colCount) sb.append("<c r=\"${colName(c)}$rowNum\" s=\"$XF_NODATA\"/>")
            sb.append("</row>")
            if (colCount > 1) merges += "A$rowNum:$lastCol$rowNum"
            rowNum++
        } else {
            rows.forEachIndexed { i, values ->
                val alt = i % 2 == 1
                sb.append("<row r=\"$rowNum\">")
                for (c in 1..colCount) {
                    val ref = "${colName(c)}$rowNum"
                    val v = values.getOrNull(c - 1)
                    val numericCol = numericColumnStart > 0 && c >= numericColumnStart
                    when {
                        v is Number && numericCol -> sb.append(numCell(ref, v.toDouble(), if (alt) XF_NUM_ALT else XF_NUM))
                        v is Number -> sb.append(numCell(ref, v.toDouble(), if (alt) XF_LEFT_ALT else XF_LEFT))
                        else -> {
                            val xf = if (numericCol) (if (alt) XF_RIGHT_ALT else XF_RIGHT) else (if (alt) XF_LEFT_ALT else XF_LEFT)
                            val s = v?.toString() ?: ""
                            sb.append(if (s.isEmpty()) "<c r=\"$ref\" s=\"$xf\"/>" else strCell(ref, s, xf))
                        }
                    }
                }
                sb.append("</row>")
                rowNum++
            }
            if (footer != null && footer.size == colCount) {
                sb.append("<row r=\"$rowNum\" ht=\"22\" customHeight=\"1\">")
                footer.forEachIndexed { c, v ->
                    val ref = "${colName(c + 1)}$rowNum"
                    when {
                        v is Number -> sb.append(numCell(ref, v.toDouble(), XF_FOOT_NUM))
                        v == null || v.toString().isEmpty() -> sb.append("<c r=\"$ref\" s=\"$XF_FOOT_LEFT\"/>")
                        else -> sb.append(strCell(ref, v.toString(), XF_FOOT_LEFT))
                    }
                }
                sb.append("</row>")
                rowNum++
            }
        }

        val sheet = buildString {
            append("""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>""")
            append("""<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">""")
            append("""<sheetViews><sheetView workbookViewId="0"><pane ySplit="$freezeAt" topLeftCell="A${freezeAt + 1}" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>""")
            append("<cols>")
            widths.forEachIndexed { i, w -> append("<col min=\"${i + 1}\" max=\"${i + 1}\" width=\"$w\" customWidth=\"1\"/>") }
            append("</cols>")
            append("<sheetData>").append(sb).append("</sheetData>")
            if (merges.isNotEmpty()) {
                append("<mergeCells count=\"${merges.size}\">")
                merges.forEach { append("<mergeCell ref=\"$it\"/>") }
                append("</mergeCells>")
            }
            append("</worksheet>")
        }

        val files = linkedMapOf(
            "[Content_Types].xml" to """<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>""",
            "_rels/.rels" to """<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>""",
            "xl/workbook.xml" to """<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="${xml(sanitizeSheetName(sheetName))}" sheetId="1" r:id="rId1"/></sheets></workbook>""",
            "xl/_rels/workbook.xml.rels" to """<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>""",
            "xl/styles.xml" to stylesXml(),
            "xl/worksheets/sheet1.xml" to sheet,
        )
        val bos = ByteArrayOutputStream()
        ZipOutputStream(bos).use { zip ->
            files.forEach { (path, content) ->
                zip.putNextEntry(ZipEntry(path))
                zip.write(content.toByteArray(Charsets.UTF_8))
                zip.closeEntry()
            }
        }
        return bos.toByteArray()
    }

    /** Saves into the public Downloads folder; returns a content Uri that can be opened. */
    fun saveToDownloads(context: Context, fileName: String, bytes: ByteArray, mime: String = MIME): Uri {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val resolver = context.contentResolver
            val values = ContentValues().apply {
                put(MediaStore.Downloads.DISPLAY_NAME, fileName)
                put(MediaStore.Downloads.MIME_TYPE, mime)
                put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS)
                put(MediaStore.Downloads.IS_PENDING, 1)
            }
            val uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values)
                ?: throw IllegalStateException("Could not create file in Downloads.")
            resolver.openOutputStream(uri)?.use { it.write(bytes) } ?: throw IllegalStateException("Could not write to Downloads.")
            values.clear()
            values.put(MediaStore.Downloads.IS_PENDING, 0)
            resolver.update(uri, values, null, null)
            return uri
        }
        val dir = context.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS) ?: context.filesDir
        val file = File(dir, fileName).apply { writeBytes(bytes) }
        @Suppress("DEPRECATION")
        (context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager)
            .addCompletedDownload(fileName, fileName, true, mime, file.absolutePath, file.length(), true)
        return FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
    }

    private fun sanitizeSheetName(name: String): String =
        name.replace(Regex("""[\\/?*\[\]:]"""), " ").replace(Regex("\\s+"), " ").trim().take(31).ifBlank { "Report" }

    private fun plain(v: Any): String = when (v) {
        is Double -> if (v == Math.floor(v) && v.isFinite()) v.toLong().toString() else v.toString()
        else -> v.toString()
    }

    private fun xml(s: String): String =
        s.filter { it == '\t' || it == '\n' || it == '\r' || it >= ' ' }
            .replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace("\"", "&quot;")

    private fun strCell(ref: String, s: String, xf: Int) =
        "<c r=\"$ref\" s=\"$xf\" t=\"inlineStr\"><is><t xml:space=\"preserve\">${xml(s)}</t></is></c>"

    private fun numCell(ref: String, v: Double, xf: Int) =
        if (!v.isFinite()) "<c r=\"$ref\" s=\"$xf\"/>"
        else "<c r=\"$ref\" s=\"$xf\"><v>${v.toBigDecimal().toPlainString()}</v></c>"

    private fun colName(index: Int): String {
        var n = index
        val sb = StringBuilder()
        while (n > 0) {
            val r = (n - 1) % 26
            sb.insert(0, ('A' + r))
            n = (n - 1) / 26
        }
        return sb.toString()
    }

    private fun stylesXml(): String {
        val fills = buildString {
            append("""<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>""")
            append(solid("FF1F4E78"))
            headerColors.forEach { append(solid(it)) }
            append(solid("FFF2F2F2"))
            append(solid("FFE7EEF7"))
        }
        val fillCount = 2 + 1 + headerColors.size + 2
        val fillAlt = 3 + headerColors.size
        val fillFoot = fillAlt + 1

        val wrapC = """<alignment horizontal="center" vertical="center" wrapText="1"/>"""
        val wrapL = """<alignment horizontal="left" vertical="center" wrapText="1"/>"""
        val wrapR = """<alignment horizontal="right" vertical="center" wrapText="1"/>"""
        val xfs = buildList {
            add("""<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>""")
            add("""<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">$wrapC</xf>""")
            add("""<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">$wrapC</xf>""")
            add("""<xf numFmtId="0" fontId="3" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>""")
            headerColors.indices.forEach { i ->
                add("""<xf numFmtId="0" fontId="4" fillId="${3 + i}" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">$wrapC</xf>""")
            }
            add("""<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">$wrapL</xf>""")
            add("""<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">$wrapR</xf>""")
            add("""<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1">$wrapR</xf>""")
            add("""<xf numFmtId="0" fontId="0" fillId="$fillAlt" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1">$wrapL</xf>""")
            add("""<xf numFmtId="0" fontId="0" fillId="$fillAlt" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1">$wrapR</xf>""")
            add("""<xf numFmtId="164" fontId="0" fillId="$fillAlt" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1">$wrapR</xf>""")
            add("""<xf numFmtId="0" fontId="5" fillId="$fillFoot" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">$wrapL</xf>""")
            add("""<xf numFmtId="164" fontId="5" fillId="$fillFoot" borderId="3" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">$wrapR</xf>""")
            add("""<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">$wrapC</xf>""")
        }
        val thin = """<left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right>"""
        return buildString {
            append("""<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">""")
            append("""<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>""")
            append("""<fonts count="6">""")
            append("""<font><sz val="11"/><name val="Calibri"/></font>""")
            append("""<font><b/><sz val="16"/><color rgb="FF0F766E"/><name val="Calibri"/></font>""")
            append("""<font><sz val="10"/><color rgb="FF334155"/><name val="Calibri"/></font>""")
            append("""<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>""")
            append("""<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>""")
            append("""<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>""")
            append("</fonts>")
            append("""<fills count="$fillCount">$fills</fills>""")
            append("""<borders count="4">""")
            append("""<border><left/><right/><top/><bottom/><diagonal/></border>""")
            append("""<border>$thin<top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border>""")
            append("""<border><left style="medium"><color rgb="FF000000"/></left><right style="medium"><color rgb="FF000000"/></right><top style="medium"><color rgb="FF000000"/></top><bottom style="medium"><color rgb="FF000000"/></bottom><diagonal/></border>""")
            append("""<border>$thin<top style="double"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border>""")
            append("</borders>")
            append("""<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>""")
            append("""<cellXfs count="${xfs.size}">""").append(xfs.joinToString("")).append("</cellXfs>")
            append("""<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>""")
            append("</styleSheet>")
        }
    }

    private fun solid(argb: String) =
        """<fill><patternFill patternType="solid"><fgColor rgb="$argb"/><bgColor indexed="64"/></patternFill></fill>"""
}
