(function($){
  $(document).ready(function(){
    // 测试API连接
    $('#test-api-btn').on('click', function(){
      var btn = $(this);
      btn.prop('disabled', true).text('测试中...');
      $.post(autoTagSeoAjax.ajaxurl, { action: 'auto_tag_seo_test_api', nonce: autoTagSeoAjax.nonce }, function(response){
        var resultDiv = $('#operation-result');
        resultDiv.removeClass('hidden success error');
        if(response && response.success){
          resultDiv.addClass('success').html('API连接成功！测试结果: ' + response.data.test_result);
        }else{
          resultDiv.addClass('error').html('API连接失败: ' + (response && response.data ? response.data : '未知错误'));
        }
      }).always(function(){
        btn.prop('disabled', false).text('测试API连接');
      });
    });

    // 批量处理
    $('#batch-process-btn').on('click', function(){
      if(!confirm('确定要批量生成当前页面的标签描述吗？')) return;
      var btn = $(this);
      var originalText = btn.text();
      btn.prop('disabled', true).text('处理中...');
      var urlParams = new URLSearchParams(window.location.search);
      var currentPage = urlParams.get('paged') || 1;
      var perPage = urlParams.get('per_page') || 10;
      var filter = urlParams.get('filter') || 'pending';

      var resultDiv = $('#operation-result');
      resultDiv.removeClass('hidden success error').addClass('success').html('已创建任务，开始后台分批处理...');

      $.post(autoTagSeoAjax.ajaxurl, {
        action: 'auto_tag_seo_batch_process',
        nonce: autoTagSeoAjax.nonce,
        current_page: currentPage,
        per_page: perPage,
        filter: filter
      }, function(response){
        resultDiv.removeClass('hidden success error');
        if(response && response.success && response.data){
          var data = response.data;

          // 检查是否为同步模式（小批量直接处理）
          if(data.sync_mode === true){
            // 同步模式：立即显示结果
            var results = data.results || {success:0,failed:0};
            resultDiv.addClass('success').html(data.message || ('批量处理完成！成功: ' + results.success + ' 个，失败: ' + results.failed + ' 个'));
            setTimeout(function(){ location.reload(); }, 1500);
            btn.prop('disabled', false).text(originalText);
            return;
          }

          // 异步模式：队列处理 - 使用强制执行机制
          if(data.job_id){
            var jobId = data.job_id;
            resultDiv.addClass('success').html(data.message || '已创建任务，开始处理...');

            var forceExecute = function(){
              $.post(autoTagSeoAjax.ajaxurl, {
                action: 'auto_tag_seo_force_execute',
                nonce: autoTagSeoAjax.nonce,
                job_id: jobId
              }, function(res){
                if(res && res.success && res.data){
                  var s = res.data.status;
                  var progress = Math.round(((s.total - s.pending) / s.total) * 100);

                  resultDiv.removeClass('hidden error').addClass('success')
                    .html('快速处理中... 进度: ' + progress + '% (' + (s.total - s.pending) + '/' + s.total + ') | 成功: ' + s.success + ' 个，失败: ' + s.failed + ' 个');

                  if(res.data.continue){
                    // 继续处理下一批，间隔时间更短
                    setTimeout(forceExecute, 600); // 0.6秒间隔，更快的处理速度
                  } else {
                    // 处理完成
                    resultDiv.removeClass('error').addClass('success')
                      .html('批量处理完成！成功: ' + s.success + ' 个，失败: ' + s.failed + ' 个 (用时更短，体验更佳)');
                    setTimeout(function(){ location.reload(); }, 1200);
                    btn.prop('disabled', false).text(originalText);
                  }
                } else {
                  resultDiv.removeClass('hidden success').addClass('error').html('处理失败，请稍后重试');
                  btn.prop('disabled', false).text(originalText);
                }
              }).fail(function(){
                resultDiv.removeClass('hidden success').addClass('error').html('网络错误，请稍后重试');
                btn.prop('disabled', false).text(originalText);
              });
            };
            forceExecute();
          } else {
            // 兼容旧返回格式
            var results = data.results || data || {success:0,failed:0};
            resultDiv.addClass('success').html('批量处理完成！成功: ' + results.success + ' 个，失败: ' + results.failed + ' 个');
            setTimeout(function(){ location.reload(); }, 1500);
            btn.prop('disabled', false).text(originalText);
          }
        } else {
          resultDiv.addClass('error').html('批量处理失败: ' + (response && response.data ? response.data : '未知错误'));
          btn.prop('disabled', false).text(originalText);
        }
      }).fail(function(){
        resultDiv.removeClass('hidden success error').addClass('error').html('请求失败，请重试');
        btn.prop('disabled', false).text(originalText);
      });
    });

    // 每页显示数量变更
    $('#per-page-select').on('change', function(){
      var perPage = $(this).val();
      var currentUrl = new URL(window.location.href);
      currentUrl.searchParams.set('per_page', perPage);
      currentUrl.searchParams.delete('paged');
      window.location.href = currentUrl.toString();
    });

    // 刷新统计
    $('#refresh-stats-btn').on('click', function(){ location.reload(); });

    // 单个生成/重新生成
    $(document).on('click', '.generate-single-btn, .regenerate-btn', function(){
      var btn = $(this);
      var termId = btn.data('term-id');
      var originalText = btn.text();
      btn.prop('disabled', true).text('生成中...');
      $.post(autoTagSeoAjax.ajaxurl, {
        action: 'auto_tag_seo_generate_single',
        nonce: autoTagSeoAjax.nonce,
        term_id: termId
      }, function(response){
        var resultDiv = $('#operation-result');
        if(response && response.success){
          resultDiv.removeClass('hidden success error').addClass('success').html('生成成功，正在刷新页面...');
          setTimeout(function(){ location.reload(); }, 1500);
        } else {
          resultDiv.removeClass('hidden success error').addClass('error').html('生成失败: ' + (response && response.data ? response.data : '未知错误'));
          btn.prop('disabled', false).text(originalText);
        }
      }).fail(function(){
        btn.prop('disabled', false).text(originalText);
      });
    });
  });
})(jQuery);

