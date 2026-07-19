# TSDM Pokemon Plugin for Discuz X5
#
# 基于官方 Discuz X5 镜像构建
# 镜像: discuz/discuzx (或官方指定镜像)
#

ARG DISCUZ_IMAGE=discuz/discuzx:latest
FROM ${DISCUZ_IMAGE}

LABEL maintainer="TSDM.net"
LABEL description="TSDM Pokemon Plugin for Discuz X5"

# 应用 Discuz X5 核心补丁
COPY patch/discuz-x5/ /var/www/html/

# 安装 Pokemon 插件
COPY plugin/ /var/www/html/source/plugin/pokemon/

# 安装 TSDM 新翼模板
COPY template/ /var/www/html/template/re_tsdm_newWing/

# 安装 Rust WASM 前端资源
COPY rust/admin/dist/ /var/www/html/source/plugin/pokemon/wasm/admin/
COPY rust/game/dist/ /var/www/html/source/plugin/pokemon/wasm/game/
COPY rust/game/dist/ /var/www/html/source/plugin/pokemon/wasm/game/

# 清理构建缓存
RUN rm -rf /tmp/* /var/tmp/*

# 设置权限
RUN chown -R www-data:www-data /var/www/html/source/plugin/pokemon/ \
    && chown -R www-data:www-data /var/www/html/template/re_tsdm_newWing/

EXPOSE 80 443

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

CMD ["php-fpm"]
