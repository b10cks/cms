<?php

return [
    'inviteSpace' => [
        'subject' => 'You\'ve been invited to :space',
        'intro' => '<strong>:inviter</strong> has invited you to collaborate on the <strong>:space</strong> space.',
        'start' => 'Click the button below to accept the invitation and get started.',
        'action' => 'Accept Invitation',
        'outro' => 'This invitation will expire :expires.',
    ],
    'inviteTeam' => [
        'subject' => 'You\'ve been invited to :team',
        'intro' => '<strong>:inviter</strong> has invited you to join the <strong>:team</strong> team.',
        'start' => 'Click the button below to accept the invitation and get started.',
        'action' => 'Accept Invitation',
        'outro' => 'This invitation will expire :expires.',
    ],
    'oneTimeToken' => [
        'subject' => 'Your one-time login code: :code',
        'greeting' => 'Hello',
        'intro' => 'Your one-time login code is below. This code will expire in 10 minutes.',
        'outro' => 'If you did not request this code, you can safely ignore this email.',
    ],
    'usageWarning' => [
        'subject' => 'Ο χώρος :space πλησιάζει το όριο :metric',
        'intro' => 'Ο χώρος σας <strong>:space</strong> έχει χρησιμοποιήσει το <strong>:percentage%</strong> του μηνιαίου ορίου :metric.',
        'detail' => 'Έχουν χρησιμοποιηθεί :used από :limit. Τίποτα δεν έχει αποκλειστεί — πρόκειται απλώς για μια ειδοποίηση, ώστε να μπορέσετε να ενεργήσετε πριν εξαντληθεί το όριο.',
        'action' => 'Έλεγχος χρήσης & πλάνων',
        'outro' => 'Εξετάστε την αναβάθμιση του πλάνου σας εάν αναμένετε ότι αυτή η χρήση θα συνεχιστεί.',
    ],
    'usageExceeded' => [
        'subject' => 'Ο χώρος :space έχει υπερβεί το όριο :metric',
        'intro' => 'Ο χώρος σας <strong>:space</strong> έχει χρησιμοποιήσει το <strong>:percentage%</strong> του μηνιαίου ορίου :metric.',
        'detail' => 'Έχουν χρησιμοποιηθεί :used από :limit. Η υπηρεσία σας συνεχίζει χωρίς διακοπή προς το παρόν, αλλά παρακαλούμε αναβαθμίστε σε ένα πλάνο που ταιριάζει στη χρήση σας.',
        'action' => 'Αναβάθμιση πλάνου',
        'outro' => 'Η παρατεταμένη υπέρβαση ενδέχεται να απαιτήσει μετάβαση σε υψηλότερο πλάνο.',
    ],
    'usageMetrics' => [
        'storage' => 'αποθηκευτικού χώρου',
        'traffic' => 'κίνησης δεδομένων',
        'ai' => 'πιστώσεων AI',
    ],
    'billingIntervals' => [
        'month' => 'μήνα',
        'year' => 'έτος',
    ],
    'paymentRequested' => [
        'subject' => 'Αίτημα πληρωμής για :space',
        'intro' => 'Ο/Η <strong>:requester</strong> σας ζητά να αναλάβετε τη συνδρομή του χώρου <strong>:space</strong>.',
        'detail' => 'Προτεινόμενο πλάνο: :plan με :price € / :interval. Θα γίνετε υπεύθυνος χρέωσης και θα λαμβάνετε όλα τα τιμολόγια.',
        'action' => 'Έλεγχος & πληρωμή',
        'outro' => 'Μπορείτε να επιλέξετε διαφορετικό πλάνο στη σελίδα συνδρομής αν αυτό δεν σας ταιριάζει.',
        'inviteMessage' => 'Ο/Η :requester σας ζητά να αναλάβετε τη συνδρομή για το «:space» (πλάνο: :plan). Αφού συνδεθείτε, ανοίξτε τις ρυθμίσεις συνδρομής του χώρου για να ολοκληρώσετε την πληρωμή.',
    ],
];
