document.addEventListener('DOMContentLoaded', () => {
    let startTime = Date.now();

    window.addEventListener('beforeunload', () => {
        const readingTime = Math.round((Date.now() - startTime) / 1000);

        navigator.sendBeacon(
            ppvAjax.ajaxUrl, // AJAX URL passed from PHP
            new URLSearchParams({
                action: 'ppv_save_reading_time',
                post_id: ppvAjax.postId, // Post ID passed from PHP
                reading_time: readingTime
            })
        );
    });
});
