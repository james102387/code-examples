document.addEventListener("DOMContentLoaded", function () {
    const chatMessages = document.getElementById("chat-messages");
    const chatInput = document.getElementById("chat-input");
    const sendButton = document.getElementById("send-button");

    sendButton.addEventListener("click", function () {
        const message = chatInput.value.trim();
        if (message !== "") {
            const messageElement = document.createElement("div");
            messageElement.className = "message";
            messageElement.textContent = message;
            chatMessages.appendChild(messageElement);
            chatInput.value = "";
        }
    });
});
