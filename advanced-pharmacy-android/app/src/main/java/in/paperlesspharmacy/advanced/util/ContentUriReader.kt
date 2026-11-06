package `in`.paperlesspharmacy.advanced.util

import android.content.Context
import android.net.Uri
import android.provider.OpenableColumns

object ContentUriReader {
    fun readBytes(context: Context, uri: Uri): ByteArray =
        context.contentResolver.openInputStream(uri)?.use { it.readBytes() }
            ?: throw IllegalArgumentException("Could not read file.")

    fun displayName(context: Context, uri: Uri): String {
        context.contentResolver.query(uri, null, null, null, null)?.use { cursor ->
            val idx = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME)
            if (idx >= 0 && cursor.moveToFirst()) {
                return cursor.getString(idx) ?: "upload.csv"
            }
        }
        return uri.lastPathSegment?.substringAfterLast('/') ?: "upload.csv"
    }
}
