/**
 * Wallet JavaScript Functions
 * Handles payment processing, ProBot monitoring, and wallet interactions
 */

let paymentTimer;
let paymentInterval;

// Run ProBot monitor manually
async function runProbotMonitor() {
    const button = event.target;
    const originalText = button.innerHTML;
    
    // Show loading state
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
    button.disabled = true;
    
    try {
        const response = await fetch('wallet.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'run_probot_monitor'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Payment check completed successfully!', 'success');
            // Refresh wallet info
            await refreshWalletInfo();
        } else {
            showToast('Error: ' + (data.error || 'Failed to check payments'), 'error');
        }
    } catch (error) {
        console.error('Error running ProBot monitor:', error);
        showToast('Network error occurred', 'error');
    } finally {
        // Restore button
        button.innerHTML = originalText;
        button.disabled = false;
    }
}

// Refresh wallet information
async function refreshWalletInfo() {
    try {
        const response = await fetch('wallet.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'get_wallet_info'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update credits display
            const creditsElement = document.querySelector('.stat-card.primary h3');
            if (creditsElement) {
                creditsElement.textContent = new Intl.NumberFormat().format(data.stats.credits || 0);
            }
            
            // Update messages remaining
            const messagesElement = document.querySelector('.stat-card.primary small');
            if (messagesElement) {
                messagesElement.textContent = `${data.stats.credits || 0} messages remaining`;
            }
            
            // Update total sent
            const sentElement = document.querySelector('.stat-card.success h3');
            if (sentElement) {
                sentElement.textContent = new Intl.NumberFormat().format(data.stats.total_messages_sent || 0);
            }
            
            // Update total spent
            const spentElement = document.querySelector('.stat-card.info h3');
            if (spentElement) {
                spentElement.textContent = new Intl.NumberFormat().format(data.stats.total_spent || 0);
            }
        }
    } catch (error) {
        console.error('Error refreshing wallet info:', error);
    }
}

// Start payment process
async function startPaymentProcess(amount, credits) {
    try {
        const response = await fetch('wallet.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'create_payment_request',
                amount: amount,
                credits: credits
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update modal with payment details
            document.getElementById('recipientId').textContent = data.recipient_id;
            document.getElementById('paymentAmount').textContent = data.amount;
            
            // Show payment modal
            document.getElementById('paymentModal').style.display = 'flex';
            
            // Start payment timer
            startPaymentTimer();
            
            // Start checking for payment
            startPaymentStatusCheck(data.payment_id, data.amount);
        } else {
            showToast('Error creating payment request: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error starting payment process:', error);
        showToast('Network error occurred', 'error');
    }
}

// Start payment timer (30 minutes)
function startPaymentTimer() {
    let timeLeft = 30 * 60; // 30 minutes in seconds
    
    paymentTimer = setInterval(() => {
        const minutes = Math.floor(timeLeft / 60);
        const seconds = timeLeft % 60;
        
        document.getElementById('paymentTimer').textContent = 
            `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        
        timeLeft--;
        
        if (timeLeft < 0) {
            clearInterval(paymentTimer);
            document.getElementById('paymentTimer').textContent = '00:00';
            updatePaymentStatus('expired', 'Payment expired');
        }
    }, 1000);
}

// Start checking payment status
function startPaymentStatusCheck(paymentId, amount) {
    paymentInterval = setInterval(async () => {
        await checkPaymentStatus(paymentId, amount);
    }, 10000); // Check every 10 seconds
}

// Check payment status
async function checkPaymentStatus(paymentId, amount) {
    try {
        const response = await fetch('wallet.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'check_payment_status',
                payment_id: paymentId,
                amount: amount
            })
        });
        
        const data = await response.json();
        
        if (data.success && data.status === 'completed') {
            updatePaymentStatus('completed', 'Payment received!');
            clearInterval(paymentInterval);
            clearInterval(paymentTimer);
            
            // Refresh wallet info
            await refreshWalletInfo();
            
            // Close modal after 3 seconds
            setTimeout(() => {
                closePaymentModal();
            }, 3000);
        }
    } catch (error) {
        console.error('Error checking payment status:', error);
    }
}

// Update payment status display
function updatePaymentStatus(status, message) {
    const statusElement = document.getElementById('paymentStatus');
    
    if (status === 'completed') {
        statusElement.innerHTML = '<i class="fas fa-check-circle" style="color: #28a745;"></i> <span>' + message + '</span>';
    } else if (status === 'expired') {
        statusElement.innerHTML = '<i class="fas fa-times-circle" style="color: #dc3545;"></i> <span>' + message + '</span>';
    } else {
        statusElement.innerHTML = '<i class="fas fa-clock"></i> <span>' + message + '</span>';
    }
}

// Close payment modal
function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
    
    // Clear timers
    if (paymentTimer) {
        clearInterval(paymentTimer);
    }
    if (paymentInterval) {
        clearInterval(paymentInterval);
    }
}

// Copy payment command
function copyCommand() {
    const command = document.getElementById('paymentCommand').textContent;
    
    navigator.clipboard.writeText(command).then(() => {
        showToast('Command copied to clipboard!', 'success');
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = command;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showToast('Command copied to clipboard!', 'success');
    });
}

// Toast notification function
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    const icon = getToastIcon(type);
    toast.innerHTML = `
        <i class="${icon}"></i>
        <span>${message}</span>
    `;
    
    const container = document.getElementById('toastContainer');
    container.appendChild(toast);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            toast.style.animation = 'slideIn 0.3s ease reverse';
            setTimeout(() => {
                if (toast.parentNode) {
                    container.removeChild(toast);
                }
            }, 300);
        }
    }, 5000);
}

function getToastIcon(type) {
    switch (type) {
        case 'success':
            return 'fas fa-check-circle';
        case 'error':
            return 'fas fa-times-circle';
        case 'warning':
            return 'fas fa-exclamation-triangle';
        default:
            return 'fas fa-info-circle';
    }
}

// Initialize wallet page
document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh wallet info every 30 seconds
    setInterval(refreshWalletInfo, 30000);
    
    // Close modal when clicking outside
    document.getElementById('paymentModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closePaymentModal();
        }
    });
});
