// 抖音作品解析下载工具 - dwdouyin（仅用于个人技术学习，禁止商用、批量爬取，遵守版权法规）
package com.dwdouyin.app.ui

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dwdouyin.app.data.DownloadManager
import com.dwdouyin.app.data.DownloadState
import kotlinx.coroutines.delay

/**
 * 底部下载浮窗 v3。
 * 关键修复：完成 / 失败提示会自己消失（不再需要点「知道了」），
 * 计时器挂在组合树外层，浮窗淡出后也照样把它收掉。
 */
@Composable
fun BoxScope.DownloadOverlay(state: DownloadState) {
    // 完成 3.2 秒、失败 4 秒后自动收起
    LaunchedEffect(state) {
        when (state) {
            is DownloadState.Done -> {
                delay(3200)
                DownloadManager.dismiss()
            }
            is DownloadState.Failed -> {
                delay(4000)
                DownloadManager.dismiss()
            }
            else -> Unit
        }
    }

    AnimatedVisibility(
        visible = state !is DownloadState.Idle,
        enter = fadeIn() + slideInVertically { it },
        exit = fadeOut() + slideOutVertically { it },
        modifier = Modifier
            .align(Alignment.BottomCenter)
            .padding(horizontal = 16.dp)
            .padding(bottom = 20.dp),
    ) {
        Column(
            Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(18.dp))
                .background(Surface1)
                .padding(horizontal = 16.dp, vertical = 14.dp),
        ) {
            when (state) {
                is DownloadState.Running -> {
                    val progress = if (state.total > 0) {
                        (state.received.toFloat() / state.total).coerceIn(0f, 1f)
                    } else 0f
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            state.filename,
                            Modifier.weight(1f),
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            style = TypeBody,
                            fontWeight = FontWeight.SemiBold,
                            color = TextDark,
                        )
                        Spacer(Modifier.width(10.dp))
                        Box(
                            Modifier
                                .clip(RoundedCornerShape(20.dp))
                                .background(Surface2)
                                .clickable { DownloadManager.cancel() }
                                .padding(horizontal = 16.dp, vertical = 8.dp),
                        ) {
                            Text("取消", color = Danger, style = TypeLabel)
                        }
                    }
                    Spacer(Modifier.height(12.dp))
                    LinearProgressIndicator(
                        progress = { progress },
                        modifier = Modifier.fillMaxWidth().height(5.dp).clip(RoundedCornerShape(3.dp)),
                        color = BluePrimary,
                        trackColor = Surface3,
                        strokeCap = StrokeCap.Round,
                    )
                    Spacer(Modifier.height(9.dp))
                    Text(
                        "${state.status} · ${formatPercent(state.received, state.total)} · ${formatSpeed(state.speedBps)}",
                        style = TypeCaption,
                        color = TextGray,
                    )
                }

                is DownloadState.Done -> {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("✓", color = Success, fontSize = 16.sp, fontWeight = FontWeight.Bold)
                        Spacer(Modifier.width(9.dp))
                        Column(Modifier.weight(1f)) {
                            Text(
                                "已保存 ${state.filename}",
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                                style = TypeBody,
                                fontWeight = FontWeight.SemiBold,
                                color = TextDark,
                            )
                            Spacer(Modifier.height(3.dp))
                            Text(
                                "这条提示会自己消失",
                                style = TypeCaption,
                                color = TextLightGray,
                            )
                        }
                    }
                    Spacer(Modifier.height(10.dp))
                    Text(
                        state.path,
                        style = TypeCaption,
                        color = TextGray,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                    )
                }

                is DownloadState.Failed -> {
                    Row(verticalAlignment = Alignment.Top) {
                        Text("!", color = Danger, fontSize = 15.sp, fontWeight = FontWeight.Bold)
                        Spacer(Modifier.width(9.dp))
                        Column(Modifier.weight(1f)) {
                            Text(
                                "下载失败",
                                style = TypeBody,
                                fontWeight = FontWeight.SemiBold,
                                color = TextDark,
                            )
                            Spacer(Modifier.height(4.dp))
                            Text(state.message, style = TypeCaption, color = Danger)
                            Spacer(Modifier.height(4.dp))
                            Text("稍后会自动收起", style = TypeCaption, color = TextLightGray)
                        }
                    }
                }

                is DownloadState.Idle -> Unit
            }
        }
    }
}

private fun formatPercent(received: Long, total: Long): String {
    if (total <= 0) return "大小未知"
    val percent = (received * 100 / total).coerceIn(0, 100)
    return "$percent%"
}

private fun formatSpeed(speedBps: Long): String = when {
    speedBps >= 1024 * 1024 -> String.format("%.1f MB/s", speedBps / 1048576.0)
    speedBps >= 1024 -> String.format("%.0f KB/s", speedBps / 1024.0)
    speedBps > 0 -> "$speedBps B/s"
    else -> "等待中"
}
