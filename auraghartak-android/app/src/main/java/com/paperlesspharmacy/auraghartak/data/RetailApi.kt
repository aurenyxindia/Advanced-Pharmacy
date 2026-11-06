package com.paperlesspharmacy.auraghartak.data

import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.paperlesspharmacy.auraghartak.BuildConfig
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.net.URLEncoder
import java.util.concurrent.TimeUnit

/**
 * Retail Paperless Pharmacy (serverdev).
 * Clients, stock, orders: keyed emr_stock_api.php calls go through the Advanced server proxy
 * (/public/retail/…) so the Retail API key never ships in the APK.
 * Products: sales.php getAvailableProducts (includes MRP) and opening_stock.php — public, called directly.
 */
class RetailApi {
    private val base = BuildConfig.RETAIL_API_BASE.trimEnd('/')
    private val proxy = BuildConfig.API_BASE.trimEnd('/') + "/public/retail"
    private val salesCache = mutableMapOf<String, List<JsonObject>>()
    private val client = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .build()

    fun placeRetailOrder(
        clientNo: String,
        orderNo: String,
        profile: Profile,
        note: String,
        deliverySlot: String,
        lines: List<CartLine>,
        paymentMethod: String = "",
    ) {
        val items = JsonArray()
        lines.forEach { line ->
            items.add(
                JsonObject().apply {
                    addProperty("p_name", line.name)
                    addProperty("qty", line.qty)
                    addProperty("unit_mrp", line.rate)
                    addProperty("rate", line.rate)
                },
            )
        }
        val body = JsonObject().apply {
            addProperty("client_no", clientNo)
            addProperty("order_no", orderNo)
            addProperty("patient_name", profile.name)
            addProperty("patient_contact", profile.phone)
            addProperty("patient_address", profile.address)
            addProperty("pincode", profile.pincode)
            addProperty("note", note)
            addProperty("delivery_slot", deliverySlot)
            if (paymentMethod.isNotBlank()) addProperty("payment_method", paymentMethod)
            add("items", items)
        }
        post("$proxy/orders", body)
    }

    fun listOrdersByPhone(phone: String): List<RetailOrderSummary> {
        if (CustomerSession.token().isBlank()) return emptyList()
        val digits = phone.filter { it.isDigit() }.takeLast(10)
        if (digits.length < 10) return emptyList()
        val root = get("$proxy/orders?phone=${enc(digits)}", auth = true)
        if (root.str("status") != "success") return emptyList()
        return root.arr("orders").mapNotNull { el ->
            val row = el.asObjectOrNull() ?: return@mapNotNull null
            val orderNo = row.str("order_no")
            if (orderNo.isBlank()) return@mapNotNull null
            RetailOrderSummary(
                orderNo = orderNo,
                pharmacyName = row.str("pharmacy_name").ifBlank { row.str("medical_name") },
                status = row.str("status").ifBlank { "Placed" },
                total = row.dbl("estimated_total"),
                createdAt = row.str("created_at").ifBlank { row.str("entry_date") },
                clientNo = row.str("client_no"),
            )
        }
    }

    fun clients(): List<Branch> {
        val root = get("$proxy/clients", auth = true)
        if (root.str("status") != "success") {
            throw ApiException(root.str("message").ifBlank { "Could not load retail pharmacies." })
        }
        return root.arr("clients").mapNotNull { el ->
            val row = el.asObjectOrNull() ?: return@mapNotNull null
            RetailBranchMapper.fromRow(row)
        }.sortedBy { it.businessName.lowercase() }
    }

    /** Dashboard medicine search — same sources as in-store catalog (sales list first). */
    fun searchProductNames(clientNo: String, query: String, limit: Int = 8): List<String> {
        val q = query.trim()
        if (q.length < 2) return emptyList()
        val cap = limit.coerceIn(1, 20)
        val fromSales = runCatching { salesProducts(clientNo, q) }.getOrNull()
            ?.take(cap)
            ?.map { it.productName }
            ?.filter { it.isNotBlank() }
            .orEmpty()
        if (fromSales.isNotEmpty()) return fromSales.distinct()
        val url = "$proxy/stock?client_no=${enc(clientNo)}" +
            "&q=${enc(q)}&only_positive=1&paginate=1&limit=$cap"
        val stock = runCatching { get(url, auth = true) }.getOrNull()
        if (stock != null && stock.str("status") == "success") {
            val names = stock.arr("items").mapNotNull { el ->
                val row = el.asObjectOrNull() ?: return@mapNotNull null
                if (ExpiryCheck.isExpired(row.str("expiry"))) return@mapNotNull null
                row.str("product_name").takeIf { it.isNotBlank() }
            }
            if (names.isNotEmpty()) return names.distinct()
        }
        return runCatching { openingStock(clientNo, q).take(cap).map { it.productName } }
            .getOrDefault(emptyList())
            .filter { it.isNotBlank() }
            .distinct()
    }

    fun products(clientNo: String, query: String): List<CatalogItem> {
        val priced = runCatching { salesProducts(clientNo, query) }.getOrNull()
        if (!priced.isNullOrEmpty()) return priced
        val fromStock = runCatching { stockApi(clientNo, query) }.getOrNull()
        if (!fromStock.isNullOrEmpty()) return fromStock
        return openingStock(clientNo, query)
    }

    /** Same product list the retail sales screen uses. Price is unit MRP, then pack MRP. */
    private fun salesProducts(clientNo: String, query: String): List<CatalogItem> {
        val rows = salesRows(clientNo)
        val q = query.trim()
        return rows.mapNotNull { row ->
            val name = row.str("p_name")
            if (name.isBlank()) return@mapNotNull null
            if (q.isNotEmpty()) {
                if (!ProductSearchMatch.matchesQuery(
                        q,
                        name,
                        row.str("generic"),
                        row.str("m_name"),
                        row.str("p_cat"),
                        row.str("batch_no"),
                    )
                ) {
                    return@mapNotNull null
                }
            }
            val units = row.dbl("live_units").takeIf { it > 0 } ?: row.dbl("availableQty")
            val packs = row.dbl("live_packs").takeIf { it > 0 } ?: row.dbl("wAvailableQty")
            val qty = if (units > 0) units else packs
            if (qty <= 0) return@mapNotNull null
            val expiry = row.str("expiry").ifBlank { row.str("expiry_date") }
            if (ExpiryCheck.isExpired(expiry)) return@mapNotNull null
            toItem(
                name = name,
                batch = row.str("batch_no"),
                pack = row.str("pack_size"),
                expiry = expiry,
                maker = row.str("m_name"),
                category = row.str("p_cat"),
                qty = qty,
                mrp = sellingPrice(row),
                unitsPerPack = unitsPerPack(row),
            )
        }.take(120)
    }

    private fun salesRows(clientNo: String): List<JsonObject> {
        val code = clientNo.trim()
        salesCache[code]?.takeIf { it.isNotEmpty() }?.let { return it }
        val url = "$base/sales.php?type=getAvailableProducts&client_no=${enc(code)}"
        val parsed = try {
            JsonParser.parseString(body(url, auth = false))
        } catch (_: Exception) {
            throw ApiException("Could not read retail products.")
        }
        if (!parsed.isJsonArray) throw ApiException("Could not read retail products.")
        val rows = parsed.asJsonArray.mapNotNull { it.asObjectOrNull() }
        salesCache[code] = rows
        return rows
    }

    private fun stockApi(clientNo: String, query: String): List<CatalogItem> {
        val url = "$proxy/stock?client_no=${enc(clientNo)}" +
            "&q=${enc(query)}&only_positive=1&paginate=1&limit=120"
        val root = get(url, auth = true)
        if (root.str("status") != "success") {
            throw ApiException(root.str("message").ifBlank { "Could not load retail stock." })
        }
        return root.arr("items").mapNotNull { el ->
            val row = el.asObjectOrNull() ?: return@mapNotNull null
            val name = row.str("product_name")
            if (name.isBlank()) return@mapNotNull null
            val units = row.dbl("available_units")
            val packs = row.dbl("available_packs")
            val qty = if (units > 0) units else packs
            if (qty <= 0 || ExpiryCheck.isExpired(row.str("expiry"))) return@mapNotNull null
            toItem(
                name = name,
                batch = row.str("batch_no"),
                pack = row.str("pack_size"),
                expiry = row.str("expiry"),
                maker = row.str("m_name"),
                category = row.str("p_cat"),
                qty = qty,
                mrp = sellingPrice(row),
                unitsPerPack = unitsPerPack(row),
            )
        }
    }

    private fun openingStock(clientNo: String, query: String): List<CatalogItem> {
        val url = "$base/opening_stock.php?type=getAllAvailableProducts&client_no=${enc(clientNo)}&search=${enc(query)}"
        val text = body(url, auth = false)
        val parsed = try {
            JsonParser.parseString(text)
        } catch (_: Exception) {
            throw ApiException("Could not read retail products.")
        }
        if (!parsed.isJsonArray) {
            throw ApiException("Could not read retail products.")
        }
        return parsed.asJsonArray.mapNotNull { el ->
            val row = el.asObjectOrNull() ?: return@mapNotNull null
            val name = row.str("p_name")
            if (name.isBlank()) return@mapNotNull null
            val units = row.dbl("current_unit_qty")
            val packs = row.dbl("current_qty")
            val qty = if (units > 0) units else packs
            if (qty <= 0 || ExpiryCheck.isExpired(row.str("expiry"))) return@mapNotNull null
            toItem(
                name = name,
                batch = row.str("batch_no"),
                pack = row.str("pack_size"),
                expiry = row.str("expiry"),
                maker = row.str("m_name"),
                category = row.str("generic"),
                qty = qty,
                mrp = sellingPrice(row),
                unitsPerPack = unitsPerPack(row),
            )
        }.take(120)
    }

    /** Per loose unit (tablet) — the app orders tablets, never whole strips. */
    private fun sellingPrice(row: JsonObject): Double {
        val unit = row.dbl("unit_mrp")
        if (unit > 0) return unit
        val pack = row.dbl("mrp")
        if (pack > 0) return pack / unitsPerPack(row)
        return row.dbl("unit_rate")
    }

    private fun unitsPerPack(row: JsonObject): Int {
        val minQ = row.dbl("minQ").toInt()
        if (minQ > 0) return minQ
        val pack = row.str("pack_size")
        Regex("(\\d+)\\s*[xX*]\\s*(\\d+)").find(pack)?.let { return it.groupValues[2].toInt().coerceAtLeast(1) }
        return pack.trim().toIntOrNull()?.coerceAtLeast(1) ?: 1
    }

    private fun toItem(
        name: String,
        batch: String,
        pack: String,
        expiry: String,
        maker: String,
        category: String,
        qty: Double,
        mrp: Double,
        unitsPerPack: Int = 1,
    ): CatalogItem {
        val detail = listOf(pack, batch, expiry, maker).filter { it.isNotBlank() }.joinToString(" · ")
        return CatalogItem(
            bindingId = retailKey("$name|$batch"),
            productName = name,
            scheduleCategory = category,
            availableQty = qty,
            mrp = mrp,
            detail = detail,
            unitsPerPack = unitsPerPack,
            packSize = pack,
        )
    }

    private fun post(url: String, body: JsonObject) {
        val media = "application/json; charset=utf-8".toMediaType()
        val builder = Request.Builder()
            .url(url)
            .post(body.toString().toRequestBody(media))
            .header("Accept", "application/json")
            .header("Content-Type", "application/json")
        withCustomerToken(builder)
        client.newCall(builder.build()).execute().use { response ->
            val text = response.body?.string().orEmpty()
            if (!response.isSuccessful || text.isBlank()) {
                throw ApiException("Could not save retail order on server.")
            }
            val root = JsonParser.parseString(text).asJsonObject
            if (root.str("status") != "success") {
                throw ApiException(root.str("message").ifBlank { "Could not save retail order." })
            }
        }
    }

    private fun get(url: String, auth: Boolean): JsonObject {
        val text = body(url, auth)
        val root = try {
            JsonParser.parseString(text).asJsonObject
        } catch (_: Exception) {
            throw ApiException("Unexpected response from the retail server.")
        }
        return root
    }

    private fun body(url: String, auth: Boolean): String {
        val builder = Request.Builder().url(url).get().header("Accept", "application/json")
        if (auth) withCustomerToken(builder)
        return try {
            client.newCall(builder.build()).execute().use { response ->
                val text = response.body?.string().orEmpty()
                if (!response.isSuccessful || text.isBlank()) {
                    throw ApiException("Retail stock is not available right now.")
                }
                text
            }
        } catch (e: ApiException) {
            throw e
        } catch (_: IOException) {
            throw ApiException("No internet connection. Check your network and try again.")
        }
    }

    private fun withCustomerToken(builder: Request.Builder) {
        val token = CustomerSession.token()
        if (token.isNotBlank()) builder.header("Authorization", "Bearer $token")
    }

    private fun enc(value: String): String = URLEncoder.encode(value, Charsets.UTF_8.name())
}

private fun placeName(raw: String): String =
    raw.trim().split(Regex("\\s+")).filter { it.isNotEmpty() }.joinToString(" ") { word ->
        word.lowercase().replaceFirstChar { it.titlecase() }
    }

private fun retailKey(value: String): Long {
    var hash = 1125899906842597L
    value.forEach { hash = 31L * hash + it.code }
    val positive = hash and Long.MAX_VALUE
    return if (positive == 0L) 1L else positive
}

private fun JsonObject.str(key: String): String {
    val el = get(key) ?: return ""
    if (el.isJsonNull || !el.isJsonPrimitive) return ""
    return el.asString.trim()
}

private fun JsonObject.dbl(key: String): Double {
    val el = get(key) ?: return 0.0
    if (el.isJsonNull || !el.isJsonPrimitive) return 0.0
    val p = el.asJsonPrimitive
    return if (p.isNumber) p.asDouble else p.asString.toDoubleOrNull() ?: 0.0
}

private fun JsonObject.arr(key: String): List<com.google.gson.JsonElement> {
    val el = get(key)
    if (el == null || !el.isJsonArray) return emptyList()
    return el.asJsonArray.toList()
}

private fun com.google.gson.JsonElement.asObjectOrNull(): JsonObject? =
    if (isJsonObject) asJsonObject else null
