// 抖音作品解析下载工具 - dwdouyin（仅用于个人技术学习，禁止商用、批量爬取，遵守版权法规）
package com.dwdouyin.app.ui

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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Download
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
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
import com.dwdouyin.app.data.HistoryDb
import com.dwdouyin.app.data.HistoryEntity
import kotlinx.coroutines.launch

/**
 * 历史记录 v3（堆叠卡片布局）：
 *   大字标题 → 整宽封面缩略行，点整行直接重下该作品；
 *   右侧两个方块按钮，一个下来一个删，不藏在图标里。
 */
@Composable
fun HistoryScreen(visible: Boolean) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var records by remember { mutableStateOf<List<HistoryEntity>>(emptyList()) }
    val downloadState by DownloadManager.state.collectAsState()
    val running = downloadState is DownloadState.Running

    LaunchedEffect(visible) {
        if (visible) records = HistoryDb.all(context)
    }

    Box(Modifier.fillMaxSize().background(PageBg)) {
        Column(Modifier.fillMaxSize()) {

            // ---- 大字标题 ----
            Row(
                Modifier
                    .fillMaxWidth()
                    .background(PageBg)
                    .padding(start = 20.dp, end = 18.dp, top = 26.dp),
                verticalAlignment = Alignment.Top,
            ) {
                Column(Modifier.weight(1f)) {
                    Text(
                        "下过的都在这",
                        fontSize = 28.sp,
                        lineHeight = 36.sp,
                        fontWeight = FontWeight.Bold,
                        color = TextDark,
                        letterSpacing = (-0.6).sp,
                    )
                    Spacer(Modifier.height(10.dp))
                    Text(
                        "点整行直接重新下载；直链过期会自动重新解析。",
                        style = TypeBody,
                        color = TextGray,
                    )
                }
            }

            Spacer(Modifier.height(22.dp))

            // ---- 统计与批量操作（一行，无卡片） ----
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 20.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    "${records.size} 条记录",
                    style = TypeLabel,
                    color = TextGray,
                    modifier = Modifier.weight(1f),
                )
                Text(
                    "刷新",
                    style = TypeLabel,
                    color = TextDark,
                    modifier = Modifier
                        .clip(RoundedCornerShape(10.dp))
                        .clickable { scope.launch { records = HistoryDb.all(context) } }
                        .padding(horizontal = 12.dp, vertical = 8.dp),
                )
                Spacer(Modifier.width(4.dp))
                Text(
                    "清空",
                    style = TypeLabel,
                    color = TextGray,
                    modifier = Modifier
                        .clip(RoundedCornerShape(10.dp))
                        .clickable {
                            scope.launch {
                                HistoryDb.clear(context)
                                records = emptyList()
                            }
                        }
                        .padding(horizontal = 12.dp, vertical = 8.dp),
                )
            }

            Spacer(Modifier.height(10.dp))

            if (records.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        Text("这里还是空的", color = TextDark, style = TypeSection)
                        Spacer(Modifier.height(8.dp))
                        Text("解析过的作品会排在这里，按时间倒序", color = TextGray, style = TypeLabel)
                    }
                }
            } else {
                LazyColumn(
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                    modifier = Modifier.padding(horizontal = 16.dp).padding(top = 4.dp),
                ) {
                    items(records, key = { it.id }) { record ->
                        HistoryRow(
                            record = record,
                            running = running,
                            onDownload = {
                                if (!running) DownloadManager.start(context, record.toWorkInfo())
                            },
                            onDelete = {
                                scope.launch {
                                    HistoryDb.delete(context, record.id)
                                    records = HistoryDb.all(context)
                                }
                            },
                        )
                    }
                    item { Spacer(Modifier.height(20.dp)) }
                }
            }
        }
    }
}

/** 堆叠行：整宽封面条 + 大字标题 + 两个方块操作。 */
@Composable
private fun HistoryRow(
    record: HistoryEntity,
    running: Boolean,
    onDownload: () -> Unit,
    onDelete: () -> Unit,
) {
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(16.dp))
            .background(Surface1)
            .clickable(enabled = !running) { onDownload() },
    ) {
        // 整宽封面条（横向裁切），压住标题
        Box(
            Modifier
                .fillMaxWidth()
                .height(120.dp)
                .background(ThumbBg),
        ) {
            AsyncImage(
                model = ImageRequest.Builder(LocalContext.current)
                    .data(record.coverUrl)
                    .addHeader("Referer", "https://www.douyin.com/")
                    .crossfade(true)
                    .build(),
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
            )
            Box(Modifier.fillMaxSize().background(PhotoScrim))
            Column(
                Modifier
                    .align(Alignment.BottomStart)
                    .padding(horizontal = 16.dp, vertical = 12.dp),
            ) {
                Text(
                    record.title.ifBlank { "无标题作品" },
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                    style = TypeSection,
                    color = Color.White,
                )
                Spacer(Modifier.height(5.dp))
                Text(
                    "@${record.author}",
                    style = TypeCaption,
                    color = Color(0xD9FFFFFF),
                )
            }
        }

        // 底部一行：类型 + 时间 + 下载 / 删除
        Row(
            Modifier.fillMaxWidth().padding(start = 16.dp, end = 10.dp, top = 12.dp, bottom = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(
                    if (record.workType == "video") "视频" else "图文",
                    style = TypeLabel,
                    fontWeight = FontWeight.SemiBold,
                    color = TextDark,
                )
                Spacer(Modifier.height(2.dp))
                Text("已保存到相册", style = TypeCaption, color = TextLightGray)
            }
            Box(
                Modifier
                    .size(40.dp)
                    .clip(RoundedCornerShape(12.dp))
                    .background(if (running) Surface3 else BluePrimary)
                    .clickable(enabled = !running) { onDownload() },
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Filled.Download,
                    contentDescription = "重新下载",
                    tint = if (running) TextGray else Color.White,
                    modifier = Modifier.size(19.dp),
                )
            }
            Spacer(Modifier.width(8.dp))
            Box(
                Modifier
                    .size(40.dp)
                    .clip(RoundedCornerShape(12.dp))
                    .background(Surface2)
                    .clickable { onDelete() },
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Filled.Delete,
                    contentDescription = "删除记录",
                    tint = Danger,
                    modifier = Modifier.size(19.dp),
                )
            }
        }
    }
}
