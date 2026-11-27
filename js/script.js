document.addEventListener("DOMContentLoaded", function () {
    const passwordInput = document.getElementById("pass");
    const toggleButton = document.getElementById("toggle");

    if (!passwordInput || !toggleButton) return;

    toggleButton.addEventListener("click", function () {
        const isPasswordHidden = passwordInput.type === "password";
        passwordInput.type = isPasswordHidden ? "text" : "password";
        toggleButton.textContent = isPasswordHidden ? "Hide" : "Show";
        toggleButton.setAttribute(
            "aria-label",
            isPasswordHidden ? "Hide password" : "Show password"
        );
    });
});
