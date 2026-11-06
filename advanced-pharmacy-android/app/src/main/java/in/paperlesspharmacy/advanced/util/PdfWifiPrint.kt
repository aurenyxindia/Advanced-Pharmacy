package `in`.paperlesspharmacy.advanced.util

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.os.Bundle
import android.os.CancellationSignal
import android.os.ParcelFileDescriptor
import android.print.PageRange
import android.print.PrintAttributes
import android.print.PrintDocumentAdapter
import android.print.PrintDocumentInfo
import android.print.PrintManager
import java.io.File
import java.io.FileInputStream
import java.io.FileOutputStream

/**
 * Opens Android's system print dialog for an existing PDF (Wi‑Fi / USB printers
 * that appear in the Print Framework). Layout stays in the PDF; the app does not
 * redraw invoices.
 */
object PdfWifiPrint {

    enum class Orientation { Portrait, Landscape }

    /**
     * @return null if the print UI opened; otherwise a short user-facing error.
     */
    fun printPdf(
        context: Context,
        bytes: ByteArray,
        jobName: String,
        orientation: Orientation = Orientation.Portrait,
    ): String? {
        if (bytes.isEmpty()) return "Nothing to print — document is empty."
        if (!looksLikePdf(bytes)) return "Document is not a valid PDF."
        val safe = sanitizeJobName(jobName)
        val file = File(context.cacheDir, "wifi_print_$safe.pdf")
        return runCatching {
            file.writeBytes(bytes)
            printPdfFile(context, file, jobName, orientation)
        }.getOrElse { it.message ?: "Could not prepare the PDF for printing." }
    }

    /**
     * @return null if the print UI opened; otherwise a short user-facing error.
     */
    fun printPdfFile(
        context: Context,
        file: File,
        jobName: String,
        orientation: Orientation = Orientation.Portrait,
    ): String? {
        if (!file.exists() || file.length() == 0L) return "Nothing to print — document is empty."
        val activity = context.findActivity()
            ?: return "Printing needs an open screen. Try again from the app."
        val pm = activity.getSystemService(Context.PRINT_SERVICE) as? PrintManager
            ?: return "Print service is not available on this device."
        val name = jobName.trim().ifBlank { "Pharmacy document" }
        val attrs = PrintAttributes.Builder()
            .setMediaSize(
                when (orientation) {
                    Orientation.Landscape -> PrintAttributes.MediaSize.ISO_A4.asLandscape()
                    Orientation.Portrait -> PrintAttributes.MediaSize.ISO_A4
                },
            )
            .setColorMode(PrintAttributes.COLOR_MODE_COLOR)
            .setMinMargins(PrintAttributes.Margins.NO_MARGINS)
            .build()
        return runCatching {
            pm.print(name, PdfFilePrintAdapter(file, name), attrs)
            null
        }.getOrElse { it.message ?: "Could not open the print dialog." }
    }

    private fun looksLikePdf(bytes: ByteArray): Boolean {
        if (bytes.size < 5) return false
        return bytes[0] == '%'.code.toByte() &&
            bytes[1] == 'P'.code.toByte() &&
            bytes[2] == 'D'.code.toByte() &&
            bytes[3] == 'F'.code.toByte()
    }

    private fun sanitizeJobName(name: String): String =
        name.replace(Regex("[^A-Za-z0-9_-]"), "_").take(48).ifBlank { "doc" }

    private fun Context.findActivity(): Activity? {
        var ctx: Context? = this
        while (ctx is ContextWrapper) {
            if (ctx is Activity) return ctx
            ctx = ctx.baseContext
        }
        return null
    }

    private class PdfFilePrintAdapter(
        private val pdfFile: File,
        private val documentName: String,
    ) : PrintDocumentAdapter() {

        override fun onLayout(
            oldAttributes: PrintAttributes?,
            newAttributes: PrintAttributes,
            cancellationSignal: CancellationSignal,
            callback: LayoutResultCallback,
            extras: Bundle?,
        ) {
            if (cancellationSignal.isCanceled) {
                callback.onLayoutCancelled()
                return
            }
            if (!pdfFile.exists() || pdfFile.length() == 0L) {
                callback.onLayoutFailed("Document is empty.")
                return
            }
            val info = PrintDocumentInfo.Builder(documentName)
                .setContentType(PrintDocumentInfo.CONTENT_TYPE_DOCUMENT)
                .setPageCount(PrintDocumentInfo.PAGE_COUNT_UNKNOWN)
                .build()
            callback.onLayoutFinished(info, oldAttributes != newAttributes)
        }

        override fun onWrite(
            pages: Array<out PageRange>,
            destination: ParcelFileDescriptor,
            cancellationSignal: CancellationSignal,
            callback: WriteResultCallback,
        ) {
            try {
                FileInputStream(pdfFile).use { input ->
                    FileOutputStream(destination.fileDescriptor).use { output ->
                        val buf = ByteArray(16 * 1024)
                        while (true) {
                            if (cancellationSignal.isCanceled) {
                                callback.onWriteCancelled()
                                return
                            }
                            val n = input.read(buf)
                            if (n < 0) break
                            output.write(buf, 0, n)
                        }
                        output.flush()
                    }
                }
                callback.onWriteFinished(arrayOf(PageRange.ALL_PAGES))
            } catch (e: Exception) {
                callback.onWriteFailed(e.message ?: "Failed to send PDF to printer.")
            }
        }
    }
}
