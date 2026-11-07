// 抖音作品解析下载工具 - dwdouyin（仅用于个人技术学习，禁止商用、批量爬取，遵守版权法规）
package com.dwdouyin.app.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import coil.request.ImageRequest
import com.dwdouyin.app.data.DownloadManager
import com.dwdouyin.app.data.DownloadState
import com.dwdouyin.app.data.MediaEntry
import com.dwdouyin.app.data.QualityPrefs
import com.dwdouyin.app.data.WorkInfo

/**
 * 结果页 v3（大字标题 + 整宽大图列表 + 大图头块叠信息 + 堆叠卡片）：
 *   · 顶部返回栏固定在滚动区之外，画质再多也点得到；
 *   · 头块是整宽封面大图，标题/作者/时长直接压在图上；
 *   · 画质改成整宽大行，点哪一行就直接下载那一档；
 *   · 底部主按钮不再依赖已选中的档位，一点就是偏好里的默认最高画质。
 */
@Composable
fun ResultScreen(
    work: WorkInfo?,
    onBack: () -> Unit,
) {
    val context = LocalContext.current
    val downloadState by DownloadManager.state.collectAsState()
    val running = downloadState is DownloadState.Running
    var toastMsg by remember { mutableStateOf<String?>(null) }

    // 默认档位（底部按钮用；也用于在列表里标"默认"）
    val defaultIndex = remember(work?.awemeId) { defaultIndex(context, work) }
    val isVideo = work?.workType == "video"

    Box(Modifier.fillMaxSize().background(PageBg)) {
        Column(Modifier.fillMaxSize()) {

            // ---- 固定返回栏（在滚动区外，永远可点）----
            Row(
                Modifier
                    .fillMaxWidth()
                    .background(PageBg)
                    .statusBarsPadding()
                    .padding(start = 10.dp, end = 20.dp, top = 8.dp, bottom = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(
                    Modifier
                        .size(44.dp)
                        .clip(RoundedCornerShape(22.dp))
                        .background(Surface1)
                        .clickable { onBack() },
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        Icons.AutoMirrored.Filled.ArrowBack,
                        contentDescription = "返回",
                        tint = TextDark,
                        modifier = Modifier.size(21.dp),
                    )
                }
                Spacer(Modifier.weight(1f))
                Text(
                    if (isVideo) "视频" else "图文",
                    style = TypeLabel,
                    color = TextGray,
                    letterSpacing = 0.6.sp,
                )
            }

            // ---- 滚动内容 ----
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {

                if (work == null) {
                    Box(Modifier.fillMaxWidth().padding(60.dp), contentAlignment = Alignment.Center) {
                        Text("暂无解析结果", color = TextGray, style = TypeBody)
                    }
                    return@Column
                }

                // ---- 大图头块：整宽封面，信息压图 ----
                Box(
                    Modifier
                        .fillMaxWidth()
                        .height(330.dp)
                        .background(ThumbBg),
                ) {
                    AsyncImage(
                        model = ImageRequest.Builder(context)
                            .data(work.coverUrl)
                            .addHeader("Referer", "https://www.douyin.com/")
                            .crossfade(true)
                            .build(),
                        contentDescription = "封面",
                        contentScale = ContentScale.Crop,
                        modifier = Modifier.fillMaxSize(),
                    )
                    // 只有压在照片上的字才用蒙版（唯一允许的渐变）
                    Box(Modifier.fillMaxSize().background(PhotoScrim))
                    Column(
                        Modifier
                            .align(Alignment.BottomStart)
                            .padding(horizontal = 20.dp, vertical = 18.dp),
                    ) {
                        Text(
                            work.title.ifBlank { "无标题作品" },
                            fontSize = 24.sp,
                            lineHeight = 32.sp,
                            fontWeight = FontWeight.Bold,
                            color = Color.White,
                            letterSpacing = (-0.5).sp,
                            maxLines = 3,
                            overflow = TextOverflow.Ellipsis,
                        )
                        Spacer(Modifier.height(8.dp))
                        Text(
                            buildString {
                                append("@")
                                append(work.author)
                                val top = work.mediaList.firstOrNull()
                                if (top != null && top.sizeBytes > 0) {
                                    append("  ·  ")
                                    append(MediaEntry.formatSize(top.sizeBytes))
                                }
                                if (top != null && top.wh.isNotBlank()) {
                                    append("  ·  ")
                                    append(top.wh)
                                }
                            },
                            style = TypeLabel,
                            color = Color(0xE6FFFFFF),
                        )
                    }
                }

                Spacer(Modifier.height(26.dp))

                // ---- 画质：整宽大行，点哪行下哪档 ----
                SectionTitle("选择清晰度")
                Spacer(Modifier.height(6.dp))
                Text(
                    "点一行就开始下载该档；底部按钮直接存默认最高画质",
                    style = TypeLabel,
                    color = TextGray,
                    modifier = Modifier.padding(horizontal = 20.dp),
                )
                Spacer(Modifier.height(14.dp))

                // 大色块网格：两列等宽卡片，行列对齐
                Column(
                    Modifier.padding(horizontal = 16.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    work.mediaList.forEachIndexed { index, entry ->
                        QualityRow(
                            entry = entry,
                            isDefault = index == defaultIndex,
                            enabled = !running,
                            onClick = { DownloadManager.start(context, work, index) },
                            onCopy = {
                                copyToClipboard(context, entry.url)
                                toastMsg = "已复制 ${entry.label} 直链"
                            },
                        )
                    }
                }

                Spacer(Modifier.height(20.dp))

                // ---- 底部主按钮：默认最高画质，不依赖手动选择 ----
                val defEntry = work.mediaList.getOrNull(defaultIndex)
                Box(
                    Modifier
                        .padding(horizontal = 16.dp)
                        .fillMaxWidth()
                        .clip(RoundedCornerShape(14.dp))
                        .background(if (running) BluePrimary.copy(alpha = 0.32f) else BluePrimary)
                        .clickable(enabled = !running && defEntry != null) {
                            if (defEntry != null) {
                                DownloadManager.start(context, work, defaultIndex)
                            }
                        }
                        .padding(vertical = 17.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        Text(
                            if (running) "下载中…" else if (isVideo) "保存最高画质" else "保存原图",
                            fontSize = 16.sp,
                            fontWeight = FontWeight.SemiBold,
                            color = Color.White,
                        )
                        if (!running && defEntry != null) {
                            Spacer(Modifier.height(3.dp))
                            Text(
                                buildString {
                                    append(defEntry.label)
                                    if (defEntry.sizeBytes > 0) {
                                        append("  ·  ")
                                        append(MediaEntry.formatSize(defEntry.sizeBytes))
                                    }
                                },
                                fontSize = 12.sp,
                                color = Color(0xB3FFFFFF),
                            )
                        }
                    }
                }

                Spacer(Modifier.height(10.dp))

                // ---- 次操作：复制链接 / 全部档位 ----
                Row(
                    Modifier.padding(horizontal = 16.dp).fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    Box(
                        Modifier
                            .weight(1f)
                            .clip(RoundedCornerShape(14.dp))
                            .background(Surface2)
                            .clickable {
                                val url = work.shareUrl.ifEmpty { "https://www.douyin.com/video/${work.awemeId}" }
                                copyToClipboard(context, url)
                                toastMsg = "已复制作品链接"
                            }
                            .padding(vertical = 15.dp),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text("复制链接", fontSize = 14.sp, color = TextDark)
                    }
                    Box(
                        Modifier
                            .weight(1f)
                            .clip(RoundedCornerShape(14.dp))
                            .background(Surface2)
                            .clickable(enabled = !running) { DownloadManager.startAll(context, work) }
                            .padding(vertical = 15.dp),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text("下载全部档位", fontSize = 14.sp, color = TextDark)
                    }
                }

                Spacer(Modifier.height(14.dp))
                Text(
                    "保存到系统相册 · 命名 日期_作者-文案",
                    fontSize = 12.sp,
                    color = TextLightGray,
                    modifier = Modifier.padding(horizontal = 20.dp),
                )
                Spacer(Modifier.height(28.dp))
            }
        }

        // ---- 轻提示（自动消失逻辑放在组合外层，visible 变化后依然生效） ----
        LaunchedEffect(toastMsg) {
            if (toastMsg != null) {
                kotlinx.coroutines.delay(1800)
                toastMsg = null
            }
        }
        AnimatedVisibility(
            visible = toastMsg != null,
            enter = fadeIn(),
            exit = fadeOut(),
            modifier = Modifier.align(Alignment.BottomCenter),
        ) {
            Box(
                Modifier
                    .padding(bottom = 48.dp)
                    .clip(RoundedCornerShape(20.dp))
                    .background(BluePrimary)
                    .padding(horizontal = 20.dp, vertical = 11.dp),
            ) {
                Text(toastMsg ?: "", color = Color.White, fontSize = 13.sp)
            }
        }
    }
}

/** 整宽画质大行：左标题 + 右侧规格 + 复制。点整行直接下载该档。 */
@Composable
private fun QualityRow(
    entry: MediaEntry,
    isDefault: Boolean,
    enabled: Boolean,
    onClick: () -> Unit,
    onCopy: () -> Unit,
) {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .background(Surface1)
            .clickable(enabled = enabled) { onClick() }
            .padding(start = 18.dp, end = 10.dp, top = 17.dp, bottom = 17.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    entry.label,
                    fontSize = 19.sp,
                    lineHeight = 26.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = TextDark,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f, fill = false),
                )
                if (isDefault) {
                    Spacer(Modifier.width(8.dp))
                    Badge("默认")
                }
            }
            val meta = buildString {
                if (entry.sizeBytes > 0) append(MediaEntry.formatSize(entry.sizeBytes))
                if (entry.wh.isNotBlank()) {
                    if (isNotEmpty()) append("  ·  ")
                    append(entry.wh)
                }
            }
            if (meta.isNotEmpty()) {
                Spacer(Modifier.height(4.dp))
                Text(meta, style = TypeLabel, color = TextGray)
            }
        }
        Box(
            Modifier
                .size(44.dp)
                .clip(RoundedCornerShape(22.dp))
                .clickable { onCopy() },
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Filled.ContentCopy,
                contentDescription = "复制直链",
                tint = TextGray,
                modifier = Modifier.size(17.dp),
            )
        }
    }
}

/** 按画质偏好算默认档位（highest / high / balanced / auto）。 */
private fun defaultIndex(context: Context, work: WorkInfo?): Int {
    val list = work?.mediaList ?: return 0
    if (list.isEmpty()) return 0
    return when (QualityPrefs.get(context)) {
        "highest" -> 0
        "high" -> list.indexOfFirst {
            Regex("1080|原画|无水印|4K", RegexOption.IGNORE_CASE).containsMatchIn(it.label)
        }.takeIf { it >= 0 } ?: 0
        "balanced" -> list.indexOfFirst { it.label.contains("720") }.takeIf { it >= 0 } ?: 0
        else -> list.indexOfFirst { it.label.contains("自动") }.takeIf { it >= 0 } ?: 0
    }
}

/** 写剪贴板。 */
private fun copyToClipboard(context: Context, text: String) {
    val cm = context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager ?: return
    cm.setPrimaryClip(ClipData.newPlainText("dwdouyin", text))
}
