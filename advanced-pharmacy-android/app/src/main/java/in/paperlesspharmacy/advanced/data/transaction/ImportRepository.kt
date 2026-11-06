package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonArray
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.arr
import `in`.paperlesspharmacy.advanced.data.dataRows
import `in`.paperlesspharmacy.advanced.data.obj
import `in`.paperlesspharmacy.advanced.data.paginatedFromApiData
import `in`.paperlesspharmacy.advanced.data.str

/** Same max as web `IMPORT_MAX_UPLOAD_MB` / API `UPLOAD_MAX_MB`. */
const val IMPORT_MAX_UPLOAD_MB = 50

class ImportRepository(private val api: ApiClient) {
    fun formats(): List<JsonObject> = api.get("import/purchase/formats").dataRows()

    fun parseCsvFile(fileBytes: ByteArray, fileName: String, format: String): JsonObject {
        return api.postMultipart(
            path = "import/purchase/parse",
            formFields = mapOf("format" to format.ifBlank { "Auto" }),
            fileField = "file",
            fileName = fileName,
            fileBytes = fileBytes,
            mimeType = when {
                fileName.endsWith(".tsv", true) -> "text/tab-separated-values"
                else -> "text/csv"
            },
        ).obj("data")
    }

    /** Server parse from CSV text (Excel converted on the device), uploaded as a file like the web. */
    fun parseCsvText(csvText: String, format: String, fileName: String = "upload.csv"): JsonObject =
        parseCsvFile(csvText.toByteArray(Charsets.UTF_8), fileName, format)

    fun matchQuery(q: String, limit: Int = 25): List<JsonObject> {
        val body = JsonObject().apply {
            addProperty("q", q)
            addProperty("limit", limit)
        }
        val data = api.post("import/purchase/match", body).obj("data")
        val matches = data.arr("matches")
        if (matches.size() > 0) {
            return (0 until matches.size()).map { matches.get(it).asJsonObject }
        }
        return data.dataRows()
    }

    /** Batch match (chunks of 40 like web) → lowercased name → top matches. */
    fun matchByNames(names: List<String>, limit: Int = 3): Map<String, List<JsonObject>> {
        val unique = names.map { it.trim() }.filter { it.isNotEmpty() }.distinctBy { it.lowercase() }
        val out = mutableMapOf<String, List<JsonObject>>()
        unique.chunked(40).forEach { chunk ->
            val arr = JsonArray()
            chunk.forEach { arr.add(it) }
            val body = JsonObject().apply {
                add("names", arr)
                addProperty("limit", limit)
            }
            val data = api.post("import/purchase/match", body).obj("data")
            val byName = data.obj("by_name")
            if (byName.size() > 0) {
                byName.entrySet().forEach { (k, v) ->
                    out[k.lowercase()] = if (v.isJsonArray) v.asJsonArray.mapNotNull { e -> e.takeIf { it.isJsonObject }?.asJsonObject } else emptyList()
                }
            } else {
                data.arr("lines").forEach { e ->
                    val line = e.takeIf { it.isJsonObject }?.asJsonObject ?: return@forEach
                    val key = line.str("product_name").lowercase()
                    if (key.isNotEmpty()) {
                        out[key] = line.arr("matches").mapNotNull { m -> m.takeIf { it.isJsonObject }?.asJsonObject }
                    }
                }
            }
        }
        return out
    }

    fun checkInvoice(invoiceNo: String): JsonObject =
        api.get("import/purchase/check-invoice", mapOf("invoice_no" to invoiceNo.trim())).obj("data")

    fun aiStatus(): JsonObject = api.get("import/purchase/ai/status").obj("data")

    /** Page images (PDF pages / photo) → AI draft lines. */
    fun extractAi(images: List<Pair<String, String>>): JsonObject {
        val arr = JsonArray()
        images.forEach { (b64, mime) ->
            arr.add(JsonObject().apply {
                addProperty("base64", b64)
                addProperty("mimeType", mime)
            })
        }
        return api.postLong("import/purchase/ai", JsonObject().apply { add("images", arr) }).obj("data")
    }

    fun gmailStatus(): JsonObject = api.get("import/gmail/status").obj("data")

    fun gmailStartUrl(): String = api.get("import/gmail/start").obj("data").str("url")

    fun gmailDisconnect() {
        api.post("import/gmail/disconnect", JsonObject())
    }

    fun gmailList(): List<JsonObject> =
        api.get("import/gmail/list").obj("data").arr("attachments")
            .mapNotNull { e -> e.takeIf { it.isJsonObject }?.asJsonObject }

    fun gmailDownload(att: JsonObject, format: String): JsonObject =
        api.get(
            "import/gmail/download",
            mapOf(
                "messageId" to att.str("messageId"),
                "attachmentId" to att.str("attachmentId"),
                "filename" to att.str("filename"),
                "format" to format,
            ),
        ).obj("data")

    /** Product master create (Add Product from import) → new id or 0. */
    fun createProduct(body: JsonObject): Int {
        val data = api.post("products", body).get("data")
        return if (data != null && data.isJsonObject) data.asJsonObject.get("id")?.takeIf { !it.isJsonNull }?.asInt ?: 0 else 0
    }

    fun manufacturers(): List<String> =
        runCatching {
            paginatedFromApiData(api.get("manufacturers", mapOf("per_page" to "500")).obj("data"))
                .rows.mapNotNull { it.str("name").trim().takeIf { n -> n.isNotEmpty() } }
        }.getOrDefault(emptyList()).distinct().sorted()
}
