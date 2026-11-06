package `in`.paperlesspharmacy.advanced.util

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Color
import android.graphics.Matrix
import android.graphics.pdf.PdfRenderer
import android.media.ExifInterface
import android.os.ParcelFileDescriptor
import android.util.Base64
import org.xmlpull.v1.XmlPullParser
import org.xmlpull.v1.XmlPullParserFactory
import java.io.ByteArrayInputStream
import java.io.ByteArrayOutputStream
import java.io.File
import java.util.zip.ZipInputStream
import kotlin.math.max
import kotlin.math.roundToInt

/** PDF/photo → page images for AI, and first Excel sheet → CSV (web exceljs / pdf.js parity). */
object ImportFileTools {
    /** Web `pdf-to-images.ts`: scale 2, max 4 pages. */
    private const val PDF_SCALE = 2f
    private const val MAX_PDF_PAGES = 4
    private const val MAX_PHOTO_SIDE = 2400

    fun isPdf(name: String, bytes: ByteArray): Boolean =
        name.endsWith(".pdf", true) ||
            (bytes.size > 4 && bytes[0] == '%'.code.toByte() && bytes[1] == 'P'.code.toByte() && bytes[2] == 'D'.code.toByte())

    /** Returns (base64, mimeType) pages ready for `import/purchase/ai`. */
    fun billToAiImages(context: Context, name: String, bytes: ByteArray): List<Pair<String, String>> =
        if (isPdf(name, bytes)) pdfToImages(context, bytes) else listOf(photoToJpeg(bytes))

    private fun pdfToImages(context: Context, bytes: ByteArray): List<Pair<String, String>> {
        val tmp = File(context.cacheDir, "import_bill_${System.currentTimeMillis()}.pdf")
        tmp.writeBytes(bytes)
        try {
            ParcelFileDescriptor.open(tmp, ParcelFileDescriptor.MODE_READ_ONLY).use { pfd ->
                PdfRenderer(pfd).use { renderer ->
                    val pages = minOf(renderer.pageCount, MAX_PDF_PAGES)
                    if (pages <= 0) throw IllegalArgumentException("PDF has no pages.")
                    return (0 until pages).map { i ->
                        renderer.openPage(i).use { page ->
                            val w = (page.width * PDF_SCALE).roundToInt().coerceAtLeast(1)
                            val h = (page.height * PDF_SCALE).roundToInt().coerceAtLeast(1)
                            val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
                            bmp.eraseColor(Color.WHITE)
                            page.render(bmp, null, null, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
                            val out = encodeJpeg(bmp, 90)
                            bmp.recycle()
                            out
                        }
                    }
                }
            }
        } finally {
            tmp.delete()
        }
    }

    private fun photoToJpeg(bytes: ByteArray): Pair<String, String> {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) throw IllegalArgumentException("Use PDF, PNG, JPG or WEBP.")
        var sample = 1
        while (max(bounds.outWidth, bounds.outHeight) / (sample * 2) >= MAX_PHOTO_SIDE) sample *= 2
        var bmp = BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
            ?: throw IllegalArgumentException("Could not read image.")
        val longSide = max(bmp.width, bmp.height)
        if (longSide > MAX_PHOTO_SIDE) {
            val f = MAX_PHOTO_SIDE.toFloat() / longSide
            bmp = Bitmap.createScaledBitmap(bmp, (bmp.width * f).roundToInt(), (bmp.height * f).roundToInt(), true)
        }
        val rotation = runCatching {
            when (ExifInterface(ByteArrayInputStream(bytes)).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)) {
                ExifInterface.ORIENTATION_ROTATE_90 -> 90f
                ExifInterface.ORIENTATION_ROTATE_180 -> 180f
                ExifInterface.ORIENTATION_ROTATE_270 -> 270f
                else -> 0f
            }
        }.getOrDefault(0f)
        if (rotation != 0f) {
            bmp = Bitmap.createBitmap(bmp, 0, 0, bmp.width, bmp.height, Matrix().apply { postRotate(rotation) }, true)
        }
        return encodeJpeg(bmp, 88)
    }

    private fun encodeJpeg(bmp: Bitmap, quality: Int): Pair<String, String> {
        val bos = ByteArrayOutputStream()
        bmp.compress(Bitmap.CompressFormat.JPEG, quality, bos)
        return Base64.encodeToString(bos.toByteArray(), Base64.NO_WRAP) to "image/jpeg"
    }

    // ───────── Excel (.xlsx) → CSV, first sheet ─────────

    fun xlsxToCsv(bytes: ByteArray): String {
        val entries = mutableMapOf<String, ByteArray>()
        ZipInputStream(ByteArrayInputStream(bytes)).use { zip ->
            var e = zip.nextEntry
            while (e != null) {
                val n = e.name
                if (n == "xl/sharedStrings.xml" || n == "xl/workbook.xml" || n == "xl/_rels/workbook.xml.rels" || n.startsWith("xl/worksheets/sheet")) {
                    entries[n] = zip.readBytes()
                }
                e = zip.nextEntry
            }
        }
        if (entries.isEmpty()) throw IllegalArgumentException("Not a valid .xlsx file. Save as .xlsx or .csv and try again.")
        val shared = entries["xl/sharedStrings.xml"]?.let { parseSharedStrings(it) }.orEmpty()
        val sheetPath = firstSheetPath(entries) ?: entries.keys.filter { it.startsWith("xl/worksheets/sheet") }.minOrNull()
            ?: throw IllegalArgumentException("Excel file has no sheets.")
        val sheet = entries[sheetPath] ?: throw IllegalArgumentException("Excel sheet not found.")
        return parseSheetToCsv(sheet, shared)
    }

    private fun parser(bytes: ByteArray): XmlPullParser =
        XmlPullParserFactory.newInstance().apply { isNamespaceAware = false }.newPullParser().apply {
            setInput(ByteArrayInputStream(bytes), "UTF-8")
        }

    private fun parseSharedStrings(bytes: ByteArray): List<String> {
        val out = mutableListOf<String>()
        val p = parser(bytes)
        var sb: StringBuilder? = null
        var inT = false
        while (p.next() != XmlPullParser.END_DOCUMENT) {
            when (p.eventType) {
                XmlPullParser.START_TAG -> when (p.name) {
                    "si" -> sb = StringBuilder()
                    "t" -> inT = true
                    "rPh" -> inT = false
                }
                XmlPullParser.TEXT -> if (inT) sb?.append(p.text)
                XmlPullParser.END_TAG -> when (p.name) {
                    "t" -> inT = false
                    "si" -> { out += sb?.toString().orEmpty(); sb = null }
                }
            }
        }
        return out
    }

    private fun firstSheetPath(entries: Map<String, ByteArray>): String? {
        val wb = entries["xl/workbook.xml"] ?: return null
        val rels = entries["xl/_rels/workbook.xml.rels"] ?: return null
        var rid: String? = null
        val p = parser(wb)
        while (p.next() != XmlPullParser.END_DOCUMENT && rid == null) {
            if (p.eventType == XmlPullParser.START_TAG && p.name == "sheet") rid = p.getAttributeValue(null, "r:id")
        }
        rid ?: return null
        val r = parser(rels)
        while (r.next() != XmlPullParser.END_DOCUMENT) {
            if (r.eventType == XmlPullParser.START_TAG && r.name == "Relationship" && r.getAttributeValue(null, "Id") == rid) {
                val target = r.getAttributeValue(null, "Target") ?: return null
                return if (target.startsWith("/")) target.trimStart('/') else "xl/$target"
            }
        }
        return null
    }

    private fun colIndex(ref: String): Int {
        var n = 0
        for (c in ref) {
            if (!c.isLetter()) break
            n = n * 26 + (c.uppercaseChar() - 'A' + 1)
        }
        return n - 1
    }

    private fun parseSheetToCsv(bytes: ByteArray, shared: List<String>): String {
        val rows = mutableListOf<String>()
        val p = parser(bytes)
        var cells = sortedMapOf<Int, String>()
        var col = 0
        var type = ""
        var value = StringBuilder()
        var inValue = false
        var nextCol = 0
        while (p.next() != XmlPullParser.END_DOCUMENT) {
            when (p.eventType) {
                XmlPullParser.START_TAG -> when (p.name) {
                    "row" -> { cells = sortedMapOf(); nextCol = 0 }
                    "c" -> {
                        val ref = p.getAttributeValue(null, "r")
                        col = if (ref.isNullOrBlank()) nextCol else colIndex(ref)
                        nextCol = col + 1
                        type = p.getAttributeValue(null, "t").orEmpty()
                        value = StringBuilder()
                    }
                    "v", "t" -> inValue = true
                }
                XmlPullParser.TEXT -> if (inValue) value.append(p.text)
                XmlPullParser.END_TAG -> when (p.name) {
                    "v", "t" -> inValue = false
                    "c" -> {
                        val raw = value.toString()
                        val v = when (type) {
                            "s" -> raw.trim().toIntOrNull()?.let { shared.getOrNull(it) }.orEmpty()
                            "b" -> if (raw == "1") "TRUE" else "FALSE"
                            else -> raw
                        }
                        if (v.isNotEmpty()) cells[col] = v
                    }
                    "row" -> if (cells.isNotEmpty()) {
                        val last = cells.lastKey()
                        rows += (0..last).joinToString(",") { csvEscape(cells[it].orEmpty()) }
                    }
                }
            }
        }
        return rows.joinToString("\n")
    }

    private fun csvEscape(s: String): String =
        if (s.contains(',') || s.contains('"') || s.contains('\n')) "\"" + s.replace("\"", "\"\"") + "\"" else s
}
