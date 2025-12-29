<script>
    // Save ID from Local Storage
    document.addEventListener('DOMContentLoaded', function () {
        try {
            const userId = window.parent.localStorage.getItem('user_id');
            const courseId = window.parent.localStorage.getItem('course_id');
            const contentDiv = document.getElementById('content');
            const userIdInput = document.getElementById('userIdInput');
            const courseIdInput = document.getElementById('courseIdInput');

            if (userId && userId !== 0) {
                userIdInput.value = userId;
                courseIdInput.value = courseId || 0;
            } else {
                const message = "Please log in to access the diagnostic exam.";
                contentDiv.style.display = 'none';
                document.body.insertAdjacentHTML('afterbegin', `<h1>${message}</h1>`);
            }
        } catch (error) {
            console.error('Error accessing parent localStorage:', error);
        }
    });

    // Summary Accordion
    document.querySelectorAll('.accordion-button').forEach(button => {
        button.addEventListener('click', function () {
            const index = this.getAttribute('data-bs-target').replace('#collapse-', '');
            const content = document.getElementById('collapse-' + index);
            const allContent = document.querySelectorAll('.accordion-collapse');

            allContent.forEach(c => {
                if (c !== content) c.style.display = 'none';
            });

            if (content.style.display === 'none' || content.style.display === '') {
                content.style.display = 'block';
            } else {
                content.style.display = 'none';
            }
        });
    });

    // Set the countdown time (in seconds)
    <?php if (isset($remainingSeconds) && (!isset($_POST['userInput']) || isset($_POST['skip']))): ?>
        let countdownTime = 10;
        countdownTime = <?php echo $remainingSeconds; ?>;

        const minutesElement = document.getElementById('minutes');
        const secondsElement = document.getElementById('seconds');
        const remainingSecondsInput = document.getElementById('remaining-seconds');
        const submitButton = document.getElementById('submitButton');

        const countdown = setInterval(() => {
            if (countdownTime <= 0) {
                clearInterval(countdown);
                submitButton.click();
                return;
            }

            let minutes = Math.floor(countdownTime / 60);
            let seconds = countdownTime % 60;

            minutesElement.textContent = String(minutes).padStart(2, '0');
            secondsElement.textContent = String(seconds).padStart(2, '0');
            countdownTime--;
        }, 1000);

        // Submit Interceptor
        document.addEventListener('DOMContentLoaded', function () {
            const skipButton = document.getElementById('skipButton');
            const loadingSpinner = document.getElementById('loadingSpinner');

            function handleButtonClick() {
                remainingSecondsInput.value = countdownTime >= 0 ? countdownTime : 0;
                loadingSpinner.style.display = 'block'; // Add Spinner
            }

            submitButton.addEventListener('click', handleButtonClick);
            skipButton.addEventListener('click', handleButtonClick);
        });
    <?php endif; ?>
</script>