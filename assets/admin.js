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
        if(response && response.success && response.data && response.data.job_id){
          var jobId = response.data.job_id;
          var poll = function(){
            $.get(autoTagSeoAjax.ajaxurl, {
              action: 'auto_tag_seo_queue_status',
              nonce: autoTagSeoAjax.nonce,
              job_id: jobId
            }, function(res){
              if(res && res.success && res.data){
                var s = res.data;
                resultDiv.removeClass('hidden error').addClass('success')
                  .html('批量处理中... 成功: ' + s.success + ' 个，失败: ' + s.failed + ' 个，剩余: ' + s.pending + ' 个 / 总计: ' + s.total + ' 个');
                if(s.done){
                  resultDiv.removeClass('error').addClass('success')
                    .html('批量处理完成！成功: ' + s.success + ' 个，失败: ' + s.failed + ' 个');
                  setTimeout(function(){ location.reload(); }, 1200);
                } else {
                  setTimeout(poll, 2000);
                }
              } else {
                resultDiv.removeClass('hidden success').addClass('error').html('查询任务状态失败，请稍后刷新重试');
                btn.prop('disabled', false).text(originalText);
              }
            }).fail(function(){
              resultDiv.removeClass('hidden success').addClass('error').html('状态轮询失败，请稍后刷新重试');
              btn.prop('disabled', false).text(originalText);
            });
          };
          poll();
        } else if (response && response.success && response.data) {
          // 兼容旧返回（立即返回统计）
          var data = response.data || {success:0,failed:0};
          resultDiv.addClass('success').html('批量处理完成！成功: ' + data.success + ' 个，失败: ' + data.failed + ' 个');
          setTimeout(function(){ location.reload(); }, 1500);
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

