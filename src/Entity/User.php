<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\UserRepository; // Ensure this class exists in the specified namespace
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['user:read']]),
        new GetCollection(normalizationContext: ['groups' => ['user:read']]),
    ],
    security: "is_granted('ROLE_USER')"
)]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read', 'event:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Groups(['user:read', 'event:read'])]
    #[Assert\NotBlank(message: 'Email is required')]
    #[Assert\Email(message: 'The email {{ value }} is not a valid email')]
    #[Assert\Length(max: 180, maxMessage: 'Email cannot be longer than {{ limit }} characters')]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    #[Assert\NotBlank(message: 'Password is required')]
    #[Assert\Length(
        min: 8,
        max: 4096,
        minMessage: 'Password must be at least {{ limit }} characters long',
        maxMessage: 'Password cannot be longer than {{ limit }} characters'
    )]
    #[Assert\Regex(
        pattern: '/^(?=.*[A-Za-z])(?=.*\d).{8,}$/',
        message: 'Password must contain at least one letter and one number'
    )]
    private ?string $password = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isVerified = false;

    #[ORM\Column(length: 6, nullable: true)]
    private ?string $verificationCode = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $verificationCodeExpiresAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['user:read', 'event:read'])]
    #[Assert\NotBlank(message: 'Name is required')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Name must be at least {{ limit }} characters long',
        maxMessage: 'Name cannot be longer than {{ limit }} characters'
    )]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z\s\'-]+$/',
        message: 'Name can only contain letters, spaces, hyphens and apostrophes'
    )]
    private ?string $name = null;

    #[ORM\Column(length: 6, nullable: true)]
    private ?string $resetCode = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $resetCodeExpiresAt = null;

    #[ORM\OneToMany(mappedBy: 'creator', targetEntity: Event::class)]
    private Collection $createdEvents;

    #[ORM\ManyToMany(targetEntity: Event::class, mappedBy: 'attendees')]
    private Collection $attendedEvents;

    public function __construct()
    {
        $this->createdEvents = new ArrayCollection();
        $this->attendedEvents = new ArrayCollection();
    }

    // Add getters and setters for all properties
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        // Explicitly clear reset code and expiration
        $this->resetCode = null;
        $this->resetCodeExpiresAt = null;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getResetCode(): ?string
    {
        if ($this->resetCode !== null && !$this->isResetCodeValid()) {
            $this->resetCode = null;
            $this->resetCodeExpiresAt = null;
        }
        return $this->resetCode;
    }

    public function setResetCode(?string $resetCode): self
    {
        $this->resetCode = $resetCode;
        if ($resetCode === null) {
            $this->resetCodeExpiresAt = null;
        }
        return $this;
    }

    public function getResetCodeExpiresAt(): ?\DateTimeInterface
    {
        if ($this->resetCodeExpiresAt !== null && !$this->isResetCodeValid()) {
            $this->resetCode = null;
            $this->resetCodeExpiresAt = null;
        }
        return $this->resetCodeExpiresAt;
    }

    public function setResetCodeExpiresAt(?\DateTimeInterface $resetCodeExpiresAt): self
    {
        $this->resetCodeExpiresAt = $resetCodeExpiresAt;
        if ($resetCodeExpiresAt === null) {
            $this->resetCode = null;
        }
        return $this;
    }

    public function isResetCodeValid(): bool
    {
        if ($this->resetCode === null || $this->resetCodeExpiresAt === null) {
            return false;
        }
        return $this->resetCodeExpiresAt > new \DateTime();
    }

    public function getCreatedEvents(): Collection
    {
        return $this->createdEvents;
    }

    public function addCreatedEvent(Event $event): self
    {
        if (!$this->createdEvents->contains($event)) {
            $this->createdEvents->add($event);
            $event->setCreator($this);
        }

        return $this;
    }

    public function removeCreatedEvent(Event $event): self
    {
        if ($this->createdEvents->removeElement($event)) {
            // set the owning side to null (unless already changed)
            if ($event->getCreator() === $this) {
                $event->setCreator(null);
            }
        }

        return $this;
    }

    public function getAttendedEvents(): Collection
    {
        return $this->attendedEvents;
    }

    public function addAttendedEvent(Event $event): self
    {
        if (!$this->attendedEvents->contains($event)) {
            $this->attendedEvents->add($event);
            $event->addAttendee($this);
        }

        return $this;
    }

    public function removeAttendedEvent(Event $event): self
    {
        if ($this->attendedEvents->removeElement($event)) {
            $event->removeAttendee($this);
        }

        return $this;
    }

    // Add UserInterface implementation methods
    public function getUsername(): string
    {
        return (string) $this->email;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): self
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getVerificationCode(): ?string
    {
        if ($this->verificationCode !== null && !$this->isVerificationCodeValid()) {
            $this->verificationCode = null;
            $this->verificationCodeExpiresAt = null;
        }
        return $this->verificationCode;
    }

    public function setVerificationCode(?string $verificationCode): self
    {
        $this->verificationCode = $verificationCode;
        if ($verificationCode === null) {
            $this->verificationCodeExpiresAt = null;
        }
        return $this;
    }

    public function getVerificationCodeExpiresAt(): ?\DateTimeInterface
    {
        if ($this->verificationCodeExpiresAt !== null && !$this->isVerificationCodeValid()) {
            $this->verificationCode = null;
            $this->verificationCodeExpiresAt = null;
        }
        return $this->verificationCodeExpiresAt;
    }

    public function setVerificationCodeExpiresAt(?\DateTimeInterface $verificationCodeExpiresAt): self
    {
        $this->verificationCodeExpiresAt = $verificationCodeExpiresAt;
        if ($verificationCodeExpiresAt === null) {
            $this->verificationCode = null;
        }
        return $this;
    }

    public function isVerificationCodeValid(): bool
    {
        if ($this->verificationCode === null || $this->verificationCodeExpiresAt === null) {
            return false;
        }
        return $this->verificationCodeExpiresAt > new \DateTime();
    }
}
