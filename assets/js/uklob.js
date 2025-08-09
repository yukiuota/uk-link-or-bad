(function ($) {

    function updateDisabled($wrap, disabled) {
        $wrap.find('.uklob-btn')
            .prop('disabled', disabled)
            .attr('aria-disabled', disabled ? 'true' : 'false')
            .attr('title', disabled ? '投票済みのため、この投稿には投票できません' : '');
        $wrap.toggleClass('uklob-disabled', disabled);
    }

    function applyVotedClass($wrap, votedType) {
        if (!votedType) return;
        $wrap.addClass('uklob-voted');
        if (votedType === 'like') {
            $wrap.addClass('uklob-voted-like');
            $wrap.find('.uklob-like').addClass('uklob-btn-voted');
        } else if (votedType === 'bad') {
            $wrap.addClass('uklob-voted-bad');
            $wrap.find('.uklob-bad').addClass('uklob-btn-voted');
        }
    }

    function refreshCounts($wrap, data) {
        $wrap.find('.uklob-like .uklob-count').text(data.like);
        $wrap.find('.uklob-bad .uklob-count').text(data.bad);
    }

    function showThankYou($wrap) {
        if (!UKLOB.thankYouMessage) return;
        var $el = $wrap.find('.uklob-thanks');
        if (!$el.length) return;
        $el.text(UKLOB.thankYouMessage).removeAttr('hidden').addClass('uklob-thanks-visible');
    }

    $(function () {
        $('.uklob').each(function () {
            var $wrap = $(this);
            var postId = $wrap.data('post-id');

            $wrap.on('click', '.uklob-btn', function () {
                var $btn = $(this);
                var type = $btn.data('type');
                updateDisabled($wrap, true);

                $.ajax({
                    url: UKLOB.ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'uklob_vote',
                        nonce: UKLOB.nonce,
                        postId: postId,
                        type: type
                    }
                }).done(function (res) {
                    if (res && res.success && res.data) {
                        refreshCounts($wrap, res.data);
                        applyVotedClass($wrap, type);
                        showThankYou($wrap);
                    } else {
                        updateDisabled($wrap, false);
                    }
                }).fail(function () {
                    updateDisabled($wrap, false);
                });
            });
        });
    });
})(jQuery);